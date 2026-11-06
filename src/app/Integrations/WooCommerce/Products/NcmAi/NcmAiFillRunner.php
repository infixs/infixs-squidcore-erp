<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCoverage;
use Infixs\SquidCore\Support\ApiErrorMessage;
use Infixs\SquidCore\Support\BackgroundQueue;
use Infixs\SquidCore\Support\OptionLock;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the AI filling in the background, one batch (ProductNcmApi::FILL_MAX products) per job of Action Scheduler.
 *
 * One batch at a time across requests (OptionLock). The job queues its next run before working, so the chain goes on
 * by itself when a request dies halfway (that batch is asked again: SquidCore only fills where it has no NCM). The
 * cursor moves past every product of a batch once each one is settled, filled or not, so no product is settled twice
 * in a run. While a batch writes, the count of products without NCM is not marked stale product by product: the run
 * asks for one new count when it ends (or stops) having filled anything, or when a batch broke after it may have
 * written.
 *
 * A temporary failure of SquidCore is tried again a minute later, up to MAX_RETRIES times in a row; any other refusal
 * ends the run as failed, and "Continue" goes on from where it stopped. The same goes for the products a batch left
 * pending (the AI did not answer most of the batch, or the second ask after sending products failed): the cursor waits
 * for them, the products of the batch already settled are skipped, and the next attempt asks the pending ones again
 * with the products that follow.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class NcmAiFillRunner {

	/**
	 * Args: [runId].
	 */
	public const HOOK = 'infixs_squidcore_ncm_ai_fill_batch';

	public const CONTINUE = 'continue';

	public const RETRY = 'retry';

	public const DONE = 'done';

	public const LOCKED = 'locked';

	public const GONE = 'gone';

	public const MAX_RETRIES = 3;

	/**
	 * A running run silent for this long, with no job queued, gets a job again (WP-Cron off, a job marked as failed).
	 */
	public const STALE_AFTER = 15 * MINUTE_IN_SECONDS;

	private const NEXT_DELAY = 5;

	private const RETRY_DELAY = 60;

	private const LOCKED_DELAY = 15;

	/**
	 * The job queued before working runs only when the request dies: after the longest batch.
	 */
	private const SAFETY_DELAY = 300;

	/**
	 * @var NcmAiFillRepository
	 */
	protected $runs;

	/**
	 * @var NcmAiFillTargets
	 */
	protected $targets;

	/**
	 * @var NcmAiFillBatch
	 */
	protected $batch;

	/**
	 * @var NcmAiAvailability
	 */
	protected $ai;

	/**
	 * @var NcmCoverage
	 */
	protected $coverage;

	/**
	 * @var OptionLock
	 */
	protected $lock;

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	public function __construct( NcmAiFillRepository $runs, NcmAiFillTargets $targets, NcmAiFillBatch $batch, NcmAiAvailability $ai, NcmCoverage $coverage, OptionLock $lock, BackgroundQueue $queue ) {
		$this->runs = $runs;
		$this->targets = $targets;
		$this->batch = $batch;
		$this->ai = $ai;
		$this->coverage = $coverage;
		$this->lock = $lock;
		$this->queue = $queue;
	}

	/**
	 * The job handler must be registered on every request (front, admin, cron).
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'handleJob' ] );
	}

	/**
	 * Replaces the pending jobs of the run with one in $delay seconds.
	 */
	public function schedule( string $runId, int $delay = 0 ): void {
		$this->unschedule( $runId );
		$this->queue->schedule( self::HOOK, [ $runId ], time() + $delay );
	}

	public function unschedule( string $runId ): void {
		$this->queue->unschedule( self::HOOK, [ $runId ] );
	}

	/**
	 * A running run that stopped by itself (silent for STALE_AFTER, no job queued) gets a job again.
	 */
	public function ensureQueued( array $run ): void {
		if ( NcmAiFillRun::isRunning( $run ) && time() - $run['heartbeat'] >= self::STALE_AFTER && ! $this->queue->isScheduled( self::HOOK, [ $run['id'] ] ) ) {
			$this->queue->schedule( self::HOOK, [ $run['id'] ], time() );
		}
	}

	/**
	 * Job of a run: a batch, then the next job. A busy lock means "later", never "give up".
	 *
	 * @param mixed $runId
	 */
	public function handleJob( $runId ): void {
		if ( ! is_string( $runId ) || ! NcmAiFillRun::isValidId( $runId ) ) {
			return;
		}

		if ( ! NcmAiFillRun::isRunning( $this->current( $runId ) ) ) {
			$this->unschedule( $runId );
			return;
		}

		// Queued before working: if this request dies, the chain goes on by itself.
		$this->queue->schedule( self::HOOK, [ $runId ], time() + self::SAFETY_DELAY );

		switch ( $this->runBatch( $runId ) ) {
			case self::CONTINUE:
				$this->schedule( $runId, self::NEXT_DELAY );
				break;
			case self::RETRY:
				$this->schedule( $runId, self::RETRY_DELAY );
				break;
			case self::LOCKED:
				$this->schedule( $runId, self::LOCKED_DELAY );
				break;
			default:
				$this->unschedule( $runId );
		}
	}

	/**
	 * One batch of the run.
	 *
	 * @return string CONTINUE, RETRY (SquidCore failed for a moment), DONE (the run ended, stopped or failed), LOCKED
	 *                (another batch is working) or GONE (the run is not the current running one).
	 */
	public function runBatch( string $runId ): string {
		if ( ! NcmAiFillRun::isRunning( $this->current( $runId ) ) ) {
			return self::GONE;
		}

		if ( ! $this->lock->acquire( NcmAiFillRepository::LOCK, NcmAiFillRepository::LOCK_TTL ) ) {
			return self::LOCKED;
		}

		$rows = [];
		$saved = null;
		// Products may have been written that no row tells (the batch broke halfway).
		$untold = false;

		// One recount at the end of the run instead of a stale mark per product.
		$this->coverage->pauseTracking();

		try {
			// Read again under the lock: another batch may have ended the run.
			$run = $this->current( $runId );

			if ( ! NcmAiFillRun::isRunning( $run ) ) {
				return self::GONE;
			}

			$run = $this->work( $run, $rows, $untold );
			$saved = $this->save( $run );

			if ( $saved !== null ) {
				$this->runs->appendItems( $runId, $rows );
			}
		} finally {
			$this->coverage->resumeTracking();
			$this->lock->release( NcmAiFillRepository::LOCK );
		}

		// Replaced while the batch worked, or broke after it may have written: what it wrote is counted again.
		if ( $untold || ( $saved === null && ! empty( $rows[ NcmAiFillRepository::FILLED ] ) ) ) {
			$this->coverage->scheduleRecompute( 0 );
		}

		if ( $saved === null ) {
			return self::GONE;
		}

		if ( $saved['status'] === NcmAiFillRun::RUNNING ) {
			return $saved['retries'] > 0 ? self::RETRY : self::CONTINUE;
		}

		$this->unschedule( $runId );
		$this->finish( $saved );

		return self::DONE;
	}

	/**
	 * After a run ended, stopped or failed: the products are counted again when it filled any.
	 */
	public function finish( array $run ): void {
		if ( $run['filled'] > 0 ) {
			$this->coverage->scheduleRecompute( 0 );
		}
	}

	/**
	 * The current run when it has this id.
	 */
	private function current( string $runId ): ?array {
		$run = $this->runs->run();

		return $run !== null && $run['id'] === $runId ? $run : null;
	}

	/**
	 * @param array $rows   Rows for the lists of the run, filled here.
	 * @param bool  $untold Set when the batch broke after it may have written products.
	 */
	private function work( array $run, array &$rows, bool &$untold ): array {
		try {
			$run = $this->fillNext( $run, $rows, $untold );
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[infixs-squidcore] NCM AI filling %s stopped: %s', $run['id'], $error->getMessage() ) );

			// The cursor of the last saved batch is kept: "Continue" asks this batch again.
			$rows = [];
			$run = $this->ended( $run, NcmAiFillRun::FAILED, sanitize_text_field( $error->getMessage() ) );
		}

		$run['heartbeat'] = time();

		return $run;
	}

	/**
	 * Asks SquidCore to fill the next products without NCM after the cursor and writes what it filled.
	 *
	 * @param array $rows   Rows for the lists of the run, filled here.
	 * @param bool  $untold Stays set when the batch breaks while it writes.
	 */
	private function fillNext( array $run, array &$rows, bool &$untold ): array {
		$unavailable = NcmAiErrors::unavailability( $this->ai );

		if ( $unavailable !== null ) {
			return $this->ended( $run, NcmAiFillRun::FAILED, NcmAiErrors::message( $unavailable ) );
		}

		$ids = $this->targets->next( $run['cursor'], ProductNcmApi::FILL_MAX, NcmAiFillRun::only( $run ), $run['skip'] );

		if ( ! $ids ) {
			return $this->ended( $run, NcmAiFillRun::DONE );
		}

		$untold = true;
		$outcome = $this->batch->process( $ids );
		$untold = false;

		if ( is_wp_error( $outcome ) ) {
			return $this->refused( $run, $outcome );
		}

		$rows = $outcome['rows'];
		$pending = $outcome['pending'];
		$run['processed'] += count( $ids ) - count( $pending );
		$run['filled'] += count( $rows[ NcmAiFillRepository::FILLED ] );
		$run['failed'] += count( $rows[ NcmAiFillRepository::FAILED ] );

		if ( $pending ) {
			// The cursor waits for the pending products; the ones settled are not asked again meanwhile.
			$run['skip'] = NcmAiFillRun::idList( array_merge( $run['skip'], array_diff( $ids, $pending ) ) );

			return $outcome['error'] !== null ? $this->refused( $run, $outcome['error'] ) : $this->retry( $run );
		}

		$run['cursor'] = max( $ids );
		$run['skip'] = array_values( array_filter( $run['skip'], function ( $id ) use ( $run ) {
			return $id > $run['cursor'];
		} ) );
		$run['retries'] = 0;
		$run['error'] = null;

		// A short batch was the last one: nothing without NCM is left after it.
		if ( count( $ids ) < ProductNcmApi::FILL_MAX ) {
			return $this->ended( $run, NcmAiFillRun::DONE );
		}

		return $run;
	}

	/**
	 * SquidCore did not fill the batch (or did not answer the second ask of it): tried again later when the failure is
	 * temporary, otherwise the run fails with the reason (the cursor stays, "Continue" asks this batch again).
	 */
	private function refused( array $run, \WP_Error $error ): array {
		if ( $this->ai->noteRouteMissing( $error ) ) {
			return $this->ended( $run, NcmAiFillRun::FAILED, NcmAiErrors::message( NcmAiErrors::UNSUPPORTED ) );
		}

		if ( ApiErrorMessage::code( $error ) === ProductNcmApi::AI_NOT_CONNECTED ) {
			return $this->ended( $run, NcmAiFillRun::FAILED, NcmAiErrors::message( NcmAiErrors::UNAVAILABLE ) );
		}

		if ( ApiErrorMessage::isTransient( $error ) ) {
			return $this->retry( $run );
		}

		return $this->ended( $run, NcmAiFillRun::FAILED, NcmAiErrors::describe( $error ) );
	}

	/**
	 * The AI or SquidCore did not answer for a moment: the batch is tried again later, up to MAX_RETRIES times in a row;
	 * then the run fails and "Continue" tries it again.
	 */
	private function retry( array $run ): array {
		++$run['retries'];

		if ( $run['retries'] < self::MAX_RETRIES ) {
			return $run;
		}

		return $this->ended( $run, NcmAiFillRun::FAILED, NcmAiErrors::message( NcmAiErrors::TEMPORARY ) );
	}

	private function ended( array $run, string $status, ?string $error = null ): array {
		$run['status'] = $status;
		$run['finishedAt'] = time();
		$run['retries'] = 0;
		$run['error'] = $error;

		return $run;
	}

	/**
	 * Saves the batch into the current run. A run stopped meanwhile keeps its status and gets the progress.
	 *
	 * @return array|null The run saved; null when the run was replaced meanwhile.
	 */
	private function save( array $run ): ?array {
		$saved = null;

		$this->runs->update( function ( ?array $current ) use ( $run, &$saved ): ?array {
			if ( $current === null || $current['id'] !== $run['id'] ) {
				return null;
			}

			if ( $current['status'] !== NcmAiFillRun::RUNNING ) {
				$run['status'] = $current['status'];
				$run['finishedAt'] = $current['finishedAt'];
				$run['error'] = $current['error'];
				$run['retries'] = 0;
			}

			$saved = $run;

			return $run;
		} );

		return $saved;
	}
}
