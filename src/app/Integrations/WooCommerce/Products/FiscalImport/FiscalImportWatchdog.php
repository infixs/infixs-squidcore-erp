<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Support\BackgroundQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Restarts a job chain of the import that stopped by itself (WP-Cron off, a job marked as failed): a run, a scan or a
 * push still under way, silent for STALE_AFTER seconds and without a job in the queue, gets a job again. A push
 * waiting for the store to connect is left to the pusher.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportWatchdog {

	/**
	 * Throttle of the admin check.
	 */
	public const TRANSIENT = 'infixs_squidcore_fiscal_import_watchdog';

	public const THROTTLE = 5 * MINUTE_IN_SECONDS;

	public const STALE_AFTER = 10 * MINUTE_IN_SECONDS;

	/**
	 * @var FiscalImportRunRepository
	 */
	protected $runs;

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	public function __construct( FiscalImportRunRepository $runs, BackgroundQueue $queue ) {
		$this->runs = $runs;
		$this->queue = $queue;
	}

	/**
	 * check(), at most once per THROTTLE.
	 */
	public function maybeCheck(): void {
		if ( get_transient( self::TRANSIENT ) !== false ) {
			return;
		}

		set_transient( self::TRANSIENT, 1, self::THROTTLE );
		$this->check();
	}

	public function check(): void {
		$state = $this->runs->state();
		$run = $state['run'];

		if ( FiscalImportRun::isRunning( $run ) && $this->isStale( $run['heartbeat'] ) ) {
			$this->revive( FiscalImportJobs::BATCH_HOOK, [ $run['id'] ] );
		}

		if ( $state['scan'] !== null && $this->isStale( (int) ( $state['scan']['heartbeat'] ?? 0 ) ) ) {
			$this->revive( FiscalImportJobs::SCAN_HOOK, [] );
		}

		// A push waiting for the connection or the catalog sync is not stalled: the admin check sends it later.
		if ( $state['push'] !== null && ( $state['push']['status'] ?? '' ) === FiscalImportPusher::RUNNING && $this->runs->pushPending() === null && $this->isStale( (int) ( $state['push']['heartbeat'] ?? 0 ) ) ) {
			$this->revive( FiscalImportJobs::PUSH_HOOK, [] );
		}
	}

	private function isStale( int $heartbeat ): bool {
		return time() - $heartbeat >= self::STALE_AFTER;
	}

	private function revive( string $hook, array $args ): void {
		if ( ! $this->queue->isScheduled( $hook, $args ) ) {
			$this->queue->schedule( $hook, $args, time() );
		}
	}
}
