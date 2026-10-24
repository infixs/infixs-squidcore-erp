<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

defined( 'ABSPATH' ) || exit;

/**
 * Opens the Import data tab once, right after the plugin is activated, when the store has NCM codes of WebmaniaBR to
 * bring: only for a single activation made by a person on the plugins screen (not network-wide, in bulk, from
 * WP-CLI, AJAX or REST), only for an administrator, and only on the first admin page after it.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportAutoOpen {

	/**
	 * Unix time of the activation, until the first admin page reads it. Autoloaded: read on every request meanwhile.
	 */
	public const OPTION = 'infixs_squidcore_open_import';

	/**
	 * An activation older than this no longer opens the tab.
	 */
	public const MAX_AGE = 600;

	/**
	 * @var FiscalImportSuggestion
	 */
	protected $suggestion;

	public function __construct( FiscalImportSuggestion $suggestion ) {
		$this->suggestion = $suggestion;
	}

	/**
	 * Called on activation.
	 *
	 * @param bool $networkWide Activated for every site of the network.
	 * @param bool $interactive A person on the plugins screen (not WP-CLI, AJAX or REST).
	 */
	public static function recordActivation( bool $networkWide, bool $interactive ): void {
		if ( $networkWide || ! $interactive ) {
			return;
		}

		update_option( self::OPTION, time(), true );
	}

	public function register(): void {
		add_action( 'wp_loaded', [ $this, 'maybeRedirect' ] );
	}

	/**
	 * On the first admin page after the activation: the option is consumed, and the tab opens when it should.
	 */
	public function maybeRedirect(): void {
		// Front pages, AJAX, REST and cron requests leave it for the admin page the person opens next (and read nothing).
		if ( ! $this->isAdminPage() ) {
			return;
		}

		$activated = get_option( self::OPTION, null );

		if ( $activated === null ) {
			return;
		}

		delete_option( self::OPTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which page is open.
		if ( isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( time() - (int) $activated > self::MAX_AGE || ! $this->suggestion->opensImportTab() ) {
			return;
		}

		wp_safe_redirect( admin_url( FiscalImportNotice::IMPORT_PATH ) );
		exit;
	}

	/**
	 * A page of the site admin a person opened, still able to redirect.
	 */
	protected function isAdminPage(): bool {
		return is_admin()
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& ! is_network_admin()
			&& ! headers_sent();
	}
}
