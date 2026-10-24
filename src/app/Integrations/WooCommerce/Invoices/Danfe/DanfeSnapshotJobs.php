<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe;

defined( 'ABSPATH' ) || exit;

/**
 * Background jobs of the simplified DANFE snapshots: the read of one order (retries, orders a print could not read
 * in time) and the backfill of the invoices authorized before.
 *
 * The handlers must be registered on every request (front, admin, cron), otherwise queued jobs would never run.
 *
 * @package Infixs\SquidCore
 * @since   1.4.0
 */
class DanfeSnapshotJobs {

	public const BUILD_HOOK = 'infixs_squidcore_invoice_danfe_build';

	public const BACKFILL_HOOK = 'infixs_squidcore_invoice_danfe_backfill';

	/**
	 * @var DanfeSnapshotService
	 */
	protected $snapshots;

	/**
	 * @var DanfeBackfill
	 */
	protected $backfill;

	public function __construct( DanfeSnapshotService $snapshots, DanfeBackfill $backfill ) {
		$this->snapshots = $snapshots;
		$this->backfill = $backfill;
	}

	public function registerHandlers(): void {
		add_action( self::BUILD_HOOK, [ $this, 'handleBuild' ] );
		add_action( self::BACKFILL_HOOK, [ $this, 'handleBackfill' ] );
		add_action( 'admin_init', [ $this->backfill, 'maybeStart' ] );
	}

	/**
	 * @param int $order_id
	 */
	public function handleBuild( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

		if ( $order instanceof \WC_Order ) {
			$this->snapshots->buildQueued( $order );
		}
	}

	public function handleBackfill(): void {
		if ( function_exists( 'wc_get_orders' ) ) {
			$this->backfill->run();
		}
	}
}
