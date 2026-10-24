<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCoverage;
use Infixs\SquidCore\Integrations\WooCommerce\Products\ProductNcmStore;
use Infixs\SquidCore\Support\BackgroundQueue;
use Infixs\SquidCore\Support\JobBudget;
use Infixs\SquidCore\Support\OptionLock;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the current import run, one batch at a time, driven by the browser (the step route) while the import screen
 * is open and by a chain of background jobs otherwise.
 *
 * - Preview: plans every product, writes nothing, keeps the counts and the lists.
 * - Apply: plans every product again at write time and writes the NCM (metas only, never a full product save, so no
 *   other plugin reacts), then verifies the products written: the ones saved with another NCM meanwhile are listed.
 * - Undo: puts back the NCM the apply replaced, except on the products edited since.
 *
 * One batch at a time across requests (OptionLock). The job queues its next run before working, so the chain goes on
 * by itself when a request dies halfway, and goes through batches for about TIME_BUDGET seconds. The cursor is saved
 * at the end of each batch, and redoing a batch is safe: a product the run already wrote counts as written again, from
 * its history entry.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportRunner {

	/**
	 * Posts per batch, variations included.
	 */
	public const BATCH_POSTS = 500;

	/**
	 * Seconds of work per batch.
	 */
	public const TIME_BUDGET = 20;

	public const CONTINUE = 'continue';

	public const DONE = 'done';

	public const LOCKED = 'locked';

	public const GONE = 'gone';

	/**
	 * Next job after a normal batch, after a busy lock, and the one queued before working (it runs only when the
	 * request dies).
	 */
	private const NEXT_DELAY = 5;

	private const LOCKED_DELAY = 15;

	private const SAFETY_DELAY = 60;

	/**
	 * Share of the memory limit a batch may reach (256 MB when there is no limit), and the share up to which a job
	 * starts another batch.
	 */
	public const MEMORY_SHARE = 0.8;

	private const JOB_MEMORY_SHARE = 0.5;

	/**
	 * @var FiscalDataSource
	 */
	protected $source;

	/**
	 * @var FiscalImportPlanner
	 */
	protected $planner;

	/**
	 * @var ProductNcmStore
	 */
	protected $store;

	/**
	 * @var FiscalImportRunRepository
	 */
	protected $runs;

	/**
	 * @var FiscalImportJournal
	 */
	protected $journal;

	/**
	 * @var OptionLock
	 */
	protected $lock;

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	/**
	 * @var NcmCoverage
	 */
	protected $coverage;

	/**
	 * @var FiscalImportPusher
	 */
	protected $pusher;

	/**
	 * @var int
	 */
	protected $batchPosts;

	/**
	 * Seconds a job goes through batches (0: one batch per job).
	 *
	 * @var int
	 */
	protected $jobSeconds;

	public function __construct( FiscalDataSource $source, FiscalImportPlanner $planner, ProductNcmStore $store, FiscalImportRunRepository $runs, FiscalImportJournal $journal, OptionLock $lock, BackgroundQueue $queue, NcmCoverage $coverage, FiscalImportPusher $pusher, int $batchPosts = self::BATCH_POSTS, int $jobSeconds = self::TIME_BUDGET ) {
		$this->source = $source;
		$this->planner = $planner;
		$this->store = $store;
		$this->runs = $runs;
		$this->journal = $journal;
		$this->lock = $lock;
		$this->queue = $queue;
		$this->coverage = $coverage;
		$this->pusher = $pusher;
		$this->batchPosts = max( 1, $batchPosts );
		$this->jobSeconds = max( 0, $jobSeconds );
	}

	/**
	 * Replaces the pending batch jobs of the run with one in $delay seconds.
	 */
	public function schedule( string $runId, int $delay = 0 ): void {
		$this->unschedule( $runId );
		$this->queue->schedule( FiscalImportJobs::BATCH_HOOK, [ $runId ], time() + $delay );
	}

	public function unschedule( string $runId ): void {
		$this->queue->unschedule( FiscalImportJobs::BATCH_HOOK, [ $runId ] );
	}

	/**
	 * Job of a run: a batch, then the next job. A busy lock means "later", never "give up".
	 */
	public function handleJob( string $runId ): void {
		if ( ! FiscalImportRun::isRunning( $this->current( $runId ) ) ) {
			$this->unschedule( $runId );
			return;
		}

		// Queued before working: if this request dies, the chain goes on by itself.
		$this->queue->schedule( FiscalImportJobs::BATCH_HOOK, [ $runId ], time() + self::SAFETY_DELAY );

		$budget = new JobBudget( $this->jobSeconds, self::JOB_MEMORY_SHARE );

		do {
			$result = $this->runBatch( $runId );

			if ( $result !== self::CONTINUE ) {
				break;
			}

			JobBudget::releaseRuntimeCache();
		} while ( ! $budget->exhausted() );

		if ( $result === self::CONTINUE ) {
			$this->schedule( $runId, self::NEXT_DELAY );
		} elseif ( $result === self::LOCKED ) {
			$this->schedule( $runId, self::LOCKED_DELAY );
		} else {
			$this->unschedule( $runId );
		}
	}

	/**
	 * One batch of the run.
	 *
	 * @return string CONTINUE, DONE (the run ended or stopped), LOCKED (another batch is working) or GONE (the run is
	 *                not the current running one).
	 */
	public function runBatch( string $runId ): string {
		if ( ! FiscalImportRun::isRunning( $this->current( $runId ) ) ) {
			return self::GONE;
		}

		if ( ! $this->lock->acquire( FiscalImportRunRepository::LOCK, FiscalImportRunRepository::LOCK_TTL ) ) {
			return self::LOCKED;
		}

		$writes = false;
		$wrote = false;
		$saved = null;

		try {
			// Read again under the lock: another batch may have ended the run.
			$run = $this->current( $runId );

			if ( ! FiscalImportRun::isRunning( $run ) ) {
				return self::GONE;
			}

			$writes = $run['mode'] !== FiscalImportRun::MODE_PREVIEW;

			if ( $writes ) {
				// One recount at the end of the run instead of a stale mark per product.
				$this->coverage->pauseTracking();
			}

			$lists = [];
			$written_before = $run['counts']['writes'];
			$run = $this->work( $run, $lists );
			$wrote = $writes && $run['counts']['writes'] > $written_before;
			$saved = $this->save( $run );

			if ( $saved !== null ) {
				$this->runs->appendItems( $runId, $lists );
			}
		} finally {
			if ( $writes ) {
				$this->coverage->resumeTracking();
			}

			$this->lock->release( FiscalImportRunRepository::LOCK );
		}

		if ( $saved === null ) {
			// Replaced while the batch worked (stopped, then another run began): what it wrote is counted again.
			if ( $wrote ) {
				$this->coverage->scheduleRecompute( 0 );
			}

			return self::GONE;
		}

		if ( $saved['status'] === FiscalImportRun::RUNNING ) {
			return self::CONTINUE;
		}

		$this->unschedule( $runId );

		if ( in_array( $saved['status'], [ FiscalImportRun::READY, FiscalImportRun::DONE ], true ) ) {
			$this->finish( $saved );
		} elseif ( $writes ) {
			// Stopped or failed halfway: the products written so far are counted again.
			$this->coverage->scheduleRecompute( 0 );
		}

		return self::DONE;
	}

	/**
	 * The current run when it has this id.
	 */
	private function current( string $runId ): ?array {
		$run = $this->runs->state()['run'];

		return $run !== null && $run['id'] === $runId ? $run : null;
	}

	/**
	 * @param array $lists Rows for the lists of the run, filled here.
	 */
	private function work( array $run, array &$lists ): array {
		try {
			if ( $run['mode'] === FiscalImportRun::MODE_UNDO ) {
				$run = $this->undoBatch( $run );
			} elseif ( $run['phase'] === FiscalImportRun::PHASE_VERIFY ) {
				$run = $this->verifyBatch( $run, $lists );
			} else {
				$run = $this->planBatch( $run, $lists );
			}
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[infixs-squidcore] Fiscal import %s stopped: %s', $run['id'], $error->getMessage() ) );

			// The cursor and counts of the last saved batch are kept: "Continue" redoes this batch, lists included.
			$lists = [];
			$run['status'] = FiscalImportRun::FAILED;
			$run['error'] = sanitize_text_field( $error->getMessage() );
		}

		$run['heartbeat'] = time();

		return $run;
	}

	/**
	 * Preview and apply: the next products, planned with the choices of the run (and written, by an apply).
	 */
	private function planBatch( array $run, array &$lists ): array {
		$ids = $this->source->nextBatch( $run['cursor'], $this->batchPosts );

		if ( ! $ids ) {
			if ( $run['mode'] === FiscalImportRun::MODE_APPLY ) {
				$run['phase'] = FiscalImportRun::PHASE_VERIFY;
				$run['cursor'] = 0;

				return $run;
			}

			return $this->ended( $run, FiscalImportRun::READY );
		}

		$apply = $run['mode'] === FiscalImportRun::MODE_APPLY;
		$choices = FiscalImportChoices::fromArray( $run['choices'], FiscalImportChoices::defaults( false ) );
		$global = $this->source->globalNcm();
		$budget = $this->batchBudget();

		foreach ( $ids as $index => $id ) {
			if ( $index > 0 && $budget->exhausted() ) {
				break;
			}

			$run['cursor'] = $id;
			++$run['processed'];
			$record = $this->source->read( $id );

			if ( $record === null ) {
				continue;
			}

			$decision = $apply ? $this->writtenByRun( $run['id'], $record, $choices, $global ) : null;

			if ( $decision !== null ) {
				// Written by this run in an attempt of this batch that did not save (a failure, a request that died).
				$written = true;
			} else {
				$decision = $this->planner->plan( $record, $this->store->stateOf( $id ), $choices, $global );
				$written = ! $apply && $decision->writes();
			}

			if ( $apply && ! $written && $decision->writes() ) {
				$decision = $this->write( $run['id'], $record, $choices, $global );

				if ( $decision === null ) {
					continue;
				}

				$written = $decision->writes();
			}

			$run['counts'] = FiscalImportCounts::add( $run['counts'], $decision, $record, $written );

			if ( $written ) {
				$run['targets'][ $decision->target() ] = ( $run['targets'][ $decision->target() ] ?? 0 ) + 1;
			}

			if ( $decision->listType() !== null ) {
				$lists[ $decision->listType() ][] = FiscalImportItems::fromDecision( $record, $decision, $written );
			}
		}

		$run['counts'] = FiscalImportCounts::withCatchAll( $run['counts'], $run['targets'] );
		$run['total'] = max( $run['total'], $run['processed'] );

		return $run;
	}

	/**
	 * The decision this run wrote on the product, planned again from the NCM it had before (its history entry); null
	 * when the run did not write it.
	 */
	private function writtenByRun( string $runId, FiscalRecord $record, FiscalImportChoices $choices, string $global ): ?FiscalDecision {
		$entry = $this->journal->entry( $record->productId() );

		if ( $entry === null || $entry['run'] !== $runId ) {
			return null;
		}

		$decision = $this->planner->plan( $record, $this->store->stateFrom( $entry['before']['own'], $entry['before']['correios'] ), $choices, $global );

		return $decision->writes() ? $decision : null;
	}

	/**
	 * Writes the NCM of one product, planned again from the product read fresh: the decision is the one of now, not
	 * the one of the preview or of the start of the batch.
	 *
	 * @return FiscalDecision|null The decision applied; null when the product is gone.
	 */
	private function write( string $runId, FiscalRecord $record, FiscalImportChoices $choices, string $global ): ?FiscalDecision {
		$product = wc_get_product( $record->productId() );

		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$before = $this->store->state( $product );
		$decision = $this->planner->plan( $record, $before, $choices, $global );

		if ( $decision->writes() ) {
			$this->store->write( $product, $decision->target() );
			$this->journal->record( $product, $runId, $before, $decision->target(), $decision->source(), $decision->origin() );
			$product->save_meta_data();
		}

		return $decision;
	}

	/**
	 * Apply, after the last product: the products the run wrote whose NCM is no longer the one written were saved
	 * while the import ran.
	 */
	private function verifyBatch( array $run, array &$lists ): array {
		$ids = $this->journal->productIds( $run['id'], $run['cursor'], $this->batchPosts );

		if ( ! $ids ) {
			return $this->ended( $run, FiscalImportRun::DONE );
		}

		_prime_post_caches( $ids, false, false );
		update_meta_cache( 'post', $ids );

		foreach ( $ids as $id ) {
			$run['cursor'] = $id;
			$entry = $this->journal->entry( $id );

			if ( $entry === null || $entry['run'] !== $run['id'] ) {
				continue;
			}

			$own = $this->store->stateOf( $id )->own();

			if ( $own === $entry['after'] ) {
				continue;
			}

			++$run['counts']['changedDuring'];
			$lists[ FiscalImportItems::CHANGED ][] = FiscalImportItems::changed( $id, (string) get_post_field( 'post_title', $id ), (string) $own, $entry );
		}

		return $run;
	}

	/**
	 * Undo: the products of the apply get back the metas they had, unless their NCM changed since.
	 */
	private function undoBatch( array $run ): array {
		$apply_id = (string) $run['parentId'];
		$ids = $this->journal->productIds( $apply_id, $run['cursor'], $this->batchPosts );

		if ( ! $ids ) {
			return $this->ended( $run, FiscalImportRun::DONE );
		}

		$budget = $this->batchBudget();

		// Loaded once for the batch (posts, terms and metas), not product by product.
		_prime_post_caches( $ids, true, true );

		foreach ( $ids as $index => $id ) {
			if ( $index > 0 && $budget->exhausted() ) {
				break;
			}

			$run['cursor'] = $id;
			$entry = $this->journal->entry( $id );
			$product = $entry !== null && $entry['run'] === $apply_id ? wc_get_product( $id ) : null;

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			++$run['processed'];
			$before = $this->store->state( $product );

			if ( ! $this->store->restore( $product, $entry['before']['own'], $entry['before']['correios'], $entry['after'] ) ) {
				++$run['counts']['keptEdited'];
				continue;
			}

			// The entry now belongs to the undo, whose push takes the restored NCM to SquidCore.
			$this->journal->record( $product, $run['id'], $before, (string) $entry['before']['own'], $entry['source'], $entry['origin'] );
			$product->save_meta_data();
			++$run['counts']['writes'];
		}

		$run['total'] = max( $run['total'], $run['processed'] );

		return $run;
	}

	private function ended( array $run, string $status ): array {
		$run['status'] = $status;
		$run['finishedAt'] = time();
		$run['total'] = max( $run['total'], $run['processed'] );

		if ( $run['mode'] === FiscalImportRun::MODE_APPLY ) {
			$run['doneNotice'] = true;
		}

		return $run;
	}

	/**
	 * Saves the batch into the current state. A run stopped meanwhile keeps its status and gets the progress.
	 *
	 * @return array|null The run saved; null when the run was replaced meanwhile.
	 */
	private function save( array $run ): ?array {
		$saved = null;

		$this->runs->update( function ( array $state ) use ( $run, &$saved ): array {
			$current = $state['run'];

			if ( $current === null || $current['id'] !== $run['id'] ) {
				return $this->keepReplaced( $state, $run );
			}

			if ( $current['status'] !== FiscalImportRun::RUNNING ) {
				$run['status'] = $current['status'];
				$run['finishedAt'] = $current['finishedAt'];
				$run['doneNotice'] = false;
			}

			$state['run'] = $run;
			$state = FiscalImportRun::settle( $state );
			$saved = $state['run'];

			return $state;
		} );

		return $saved;
	}

	/**
	 * An import stopped and replaced by another run while its batch worked: the products that batch wrote still count
	 * in it, as the last import (undoable), the way a stop after the batch would have left it.
	 */
	private function keepReplaced( array $state, array $run ): array {
		if ( $run['mode'] !== FiscalImportRun::MODE_APPLY || $run['counts']['writes'] <= 0 ) {
			return $state;
		}

		$last = $state['last'];

		if ( $last !== null && $last['id'] !== $run['id'] && $last['startedAt'] > $run['startedAt'] ) {
			return $state;
		}

		$run['status'] = $last !== null && $last['id'] === $run['id'] ? $last['status'] : FiscalImportRun::STOPPED;
		$run['finishedAt'] = $last !== null && $last['id'] === $run['id'] ? $last['finishedAt'] : time();
		$run['doneNotice'] = false;
		$run['undoable'] = true;
		$state['last'] = $run;

		return $state;
	}

	/**
	 * After a run ended: the products written go to SquidCore, the products are counted again and the next scan
	 * starts from the new data (a scan under way, planned before the writes, is dropped).
	 */
	private function finish( array $run ): void {
		if ( $run['mode'] !== FiscalImportRun::MODE_PREVIEW ) {
			$this->runs->forgetScan();
			$this->runs->update( function ( array $state ): array {
				$state['scan'] = null;

				return $state;
			} );
			$this->coverage->scheduleRecompute( 0 );

			if ( $run['counts']['writes'] > 0 ) {
				$this->pusher->request( $run['id'] );
			}
		}

		/**
		 * A preview, an import or an undo of the NCM finished.
		 *
		 * @since 1.3.0
		 *
		 * @param string $runId
		 * @param string $mode   preview, apply or undo.
		 * @param array  $counts Totals of the run.
		 */
		do_action( 'infixs_squidcore_fiscal_import_finished', $run['id'], $run['mode'], $run['counts'] );
	}

	/**
	 * Time and memory of one batch.
	 */
	private function batchBudget(): JobBudget {
		return new JobBudget( self::TIME_BUDGET, self::MEMORY_SHARE );
	}
}
