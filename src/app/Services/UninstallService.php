<?php

namespace Infixs\SquidCore\Services;

use Infixs\SquidCore\Integrations\WooCommerce\ActivityRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin\InvoiceAttentionNotice;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeBackfill;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeSnapshotJobs;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeSnapshotRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceJobs;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit\InvoiceUsage;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit\PlanLimitRetry;
use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaBR;
use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaNumberingCheck;
use Infixs\SquidCore\Integrations\WooCommerce\Products\CorreiosNcmCopy;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportAutoOpen;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportJobs;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportJournal;
use Infixs\SquidCore\Integrations\WooCommerce\Products\ProductNcmStore;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportRunRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportWatchdog;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCoverage;
use Infixs\SquidCore\Integrations\WooCommerce\SyncDispatcher;
use Infixs\SquidCore\Integrations\WooCommerce\SyncStatusRepository;
use Infixs\SquidCore\Integrations\WooCommerce\WooCommerceApiKeys;
use Infixs\SquidCore\Support\BackgroundQueue;
use Infixs\SquidCore\Support\FreshOption;
use Infixs\SquidCore\Support\NoticeDismissals;
use Infixs\SquidCore\Support\OAuth\OAuthRevocation;
use Infixs\SquidCore\Support\OAuth\OAuthTokenStore;

defined( 'ABSPATH' ) || exit;

/**
 * Deleting the plugin cuts what it gave SquidCore: an OAuth connection is ended in SquidCore, the WooCommerce REST API
 * keys the plugin created are removed and the stored credentials (OAuth token, plugin token) go with the settings, as
 * do the outcome and the activity of the pushes the dashboard showed, the pending background jobs and the installed
 * version. The simplified DANFE data kept in the orders goes too: it is a cache of the invoice XML.
 *
 * Runs from uninstall.php, with the plugin not loaded: it only takes classes that need no container.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class UninstallService {

	/**
	 * Option of the plugin settings (AvelPress names it after the app id), which holds the plugin token.
	 */
	public const SETTINGS_OPTION = 'infixs_squidcore_settings';

	/**
	 * Option with the installed version, which tells the plugin when to run its upgrade routine.
	 */
	public const VERSION_OPTION = 'infixs_squidcore_version';

	/**
	 * Hooks of the background jobs, for the WP-Cron fallback (Action Scheduler jobs go by their group).
	 */
	private const JOB_HOOKS = [
		SyncDispatcher::HOOK,
		InvoiceJobs::EMIT_HOOK,
		InvoiceJobs::POLL_HOOK,
		DanfeSnapshotJobs::BUILD_HOOK,
		DanfeSnapshotJobs::BACKFILL_HOOK,
		PlanLimitRetry::HOOK,
		InvoiceUsage::HOOK,
		CorreiosNcmCopy::HOOK,
		NcmCoverage::HOOK,
		FiscalImportJobs::BATCH_HOOK,
		FiscalImportJobs::SCAN_HOOK,
		FiscalImportJobs::PUSH_HOOK,
	];

	/**
	 * Options of the NCM copy, the NCM count, the fiscal import (state, lists, pending push, lock, activation mark), the
	 * DANFE backfill and the re-emission of the invoices the plan limit refused.
	 */
	private const FEATURE_OPTIONS = [
		CorreiosNcmCopy::OPTION,
		NcmCoverage::OPTION,
		NcmCoverage::DIRTY_OPTION,
		FiscalImportRunRepository::OPTION,
		FiscalImportRunRepository::ITEMS_OPTION,
		FiscalImportRunRepository::PUSH_PENDING_OPTION,
		FiscalImportRunRepository::LOCK,
		FiscalImportAutoOpen::OPTION,
		DanfeBackfill::OPTION,
		PlanLimitRetry::OPTION,
	];

	/**
	 * Cached results of the fiscal import (WebmaniaBR probe, scan, watchdog throttle and numbering check), the use of
	 * the month of the plan and the count of invoices the plan limit refused.
	 */
	private const FEATURE_TRANSIENTS = [
		WebmaniaBR::PROBE_TRANSIENT,
		FiscalImportRunRepository::SCAN_TRANSIENT,
		FiscalImportWatchdog::TRANSIENT,
		WebmaniaNumberingCheck::TRANSIENT,
		InvoiceUsage::TRANSIENT,
		InvoiceAttentionNotice::PLAN_LIMIT_TRANSIENT,
		ConnectionService::VERIFY_TRANSIENT,
	];

	/**
	 * Transients of "Conectar com SquidCore": pending requests, outcome of the returns and cached metadata.
	 */
	private const TRANSIENT_PREFIX = 'infixs_squidcore_oauth_';

	/**
	 * @var OAuthTokenStore
	 */
	protected $oauth;

	/**
	 * @var WooCommerceApiKeys
	 */
	protected $apiKeys;

	/**
	 * @var OAuthRevocation
	 */
	protected $revocation;

	/**
	 * @var SyncStatusRepository
	 */
	protected $syncStatus;

	/**
	 * @var ActivityRepository
	 */
	protected $activity;

	public function __construct( OAuthTokenStore $oauth, WooCommerceApiKeys $apiKeys, OAuthRevocation $revocation, SyncStatusRepository $syncStatus, ActivityRepository $activity ) {
		$this->oauth = $oauth;
		$this->apiKeys = $apiKeys;
		$this->revocation = $revocation;
		$this->syncStatus = $syncStatus;
		$this->activity = $activity;
	}

	public function run(): void {
		$oauth = $this->oauth->get();

		if ( $oauth ) {
			// Best effort: the plugin is deleted all the same. A token SquidCore refused is not sent.
			if ( empty( $oauth['needs_reconnect'] ) && ! empty( $oauth['api_url'] ) ) {
				$this->revocation->end( (string) $oauth['api_url'], (string) $oauth['access_token'] );
			}

			$this->apiKeys->delete( (int) ( $oauth['wc_key_id'] ?? 0 ) );
		}

		// The key of an OAuth connection a plugin token replaced.
		$settings = FreshOption::get( self::SETTINGS_OPTION );
		$connection = is_array( $settings ) && isset( $settings['connection'] ) && is_array( $settings['connection'] ) ? $settings['connection'] : [];
		$this->apiKeys->delete( (int) ( $connection['wc_key_id'] ?? 0 ) );

		$this->oauth->delete();
		$this->deleteTransients();
		$this->syncStatus->clear();
		$this->activity->clear();
		$this->unscheduleJobs();
		delete_transient( InvoiceAttentionNotice::TRANSIENT );
		$this->deleteFeatureData();
		$this->deleteDanfeSnapshots();
		delete_option( self::SETTINGS_OPTION );
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * What the NCM copy, the NCM count and the fiscal import kept. The NCM of the products (SquidCore and Correios
	 * metas) stays: it is the store's data. WebmaniaBR data is never touched.
	 */
	private function deleteFeatureData(): void {
		global $wpdb;

		foreach ( self::FEATURE_OPTIONS as $option ) {
			delete_option( $option );
		}

		foreach ( self::FEATURE_TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}

		// The history of the import and the Correios NCM mirror on each product, and the notices each user dismissed in
		// this site.
		delete_metadata( 'post', 0, FiscalImportJournal::META_KEY, '', true );
		delete_metadata( 'post', 0, ProductNcmStore::MIRROR_META_KEY, '', true );
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . NoticeDismissals::OPTION, '', true );
	}

	/**
	 * The simplified DANFE snapshots of the orders, on the posts storage and in the order tables (HPOS). The invoice
	 * record of the orders stays: it is the store's data.
	 */
	private function deleteDanfeSnapshots(): void {
		global $wpdb;

		delete_metadata( 'post', 0, DanfeSnapshotRepository::META_KEY, '', true );

		$table = $wpdb->prefix . 'wc_orders_meta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the order tables have no metadata API of their own outside WooCommerce.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time cleanup on uninstall.
		$wpdb->delete( $table, [ 'meta_key' => DanfeSnapshotRepository::META_KEY ] );
	}

	/**
	 * Pending sync and invoice jobs would run code that is gone.
	 */
	private function unscheduleJobs(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], BackgroundQueue::GROUP );
		}

		foreach ( self::JOB_HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	private function deleteTransients(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the names are hashes, so they are found by prefix.
		$names = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%'
		) );

		foreach ( $names as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
	}
}
