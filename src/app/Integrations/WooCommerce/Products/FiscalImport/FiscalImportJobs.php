<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

defined( 'ABSPATH' ) || exit;

/**
 * Background jobs of the import (batches of a run, the scan, the push to SquidCore) and the admin checks that keep
 * them going: a push waiting for the connection, and the watchdog of stalled job chains.
 *
 * The handlers must be registered on every request (front, admin, cron), otherwise queued jobs would never run.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportJobs {

	/**
	 * Args: [runId].
	 */
	public const BATCH_HOOK = 'infixs_squidcore_fiscal_import_batch';

	public const SCAN_HOOK = 'infixs_squidcore_fiscal_import_scan';

	public const PUSH_HOOK = 'infixs_squidcore_fiscal_import_push';

	public const HOOKS = [ self::BATCH_HOOK, self::SCAN_HOOK, self::PUSH_HOOK ];

	/**
	 * @var FiscalImportRunner
	 */
	protected $runner;

	/**
	 * @var FiscalImportScanner
	 */
	protected $scanner;

	/**
	 * @var FiscalImportPusher
	 */
	protected $pusher;

	/**
	 * @var FiscalImportWatchdog
	 */
	protected $watchdog;

	public function __construct( FiscalImportRunner $runner, FiscalImportScanner $scanner, FiscalImportPusher $pusher, FiscalImportWatchdog $watchdog ) {
		$this->runner = $runner;
		$this->scanner = $scanner;
		$this->pusher = $pusher;
		$this->watchdog = $watchdog;
	}

	public function register(): void {
		add_action( self::BATCH_HOOK, [ $this, 'handleBatch' ] );
		add_action( self::SCAN_HOOK, [ $this, 'handleScan' ] );
		add_action( self::PUSH_HOOK, [ $this, 'handlePush' ] );
		add_action( 'admin_init', [ $this, 'onAdminInit' ] );
	}

	/**
	 * @param mixed $runId
	 */
	public function handleBatch( $runId ): void {
		if ( is_string( $runId ) && FiscalImportRun::isValidId( $runId ) ) {
			$this->runner->handleJob( $runId );
		}
	}

	public function handleScan(): void {
		$this->scanner->handle();
	}

	public function handlePush(): void {
		$this->pusher->handle();
	}

	/**
	 * A push waiting for the store goes out once it can (the read of an autoloaded option otherwise), and the
	 * watchdog looks at the job chains every few minutes.
	 */
	public function onAdminInit(): void {
		$this->pusher->checkPending();

		if ( current_user_can( 'manage_options' ) ) {
			$this->watchdog->maybeCheck();
		}
	}
}
