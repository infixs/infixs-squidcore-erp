<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosAutomatico;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCode;
use Infixs\SquidCore\Integrations\WooCommerce\Products\ProductNcmStore;
use Infixs\SquidCore\Support\BackgroundQueue;
use Infixs\SquidCore\Support\JobBudget;
use Infixs\SquidCore\Support\OptionLock;

defined( 'ABSPATH' ) || exit;

/**
 * Background scan that gives the numbers of the suggestion and of the import screen: every product planned with the
 * default choices, nothing written. The result is cached for 12 hours, and forgotten when an import or an undo ends.
 *
 * Shares the lock of the runs, since both write the import state. A run of the job goes through batches for about
 * FiscalImportRunner::TIME_BUDGET seconds, so a store whose cron runs one job a minute is scanned in a few runs.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportScanner {

	public const CONTINUE = 'continue';

	public const DONE = 'done';

	public const LOCKED = 'locked';

	private const NEXT_DELAY = 5;

	private const LOCKED_DELAY = 15;

	/**
	 * Share of the memory limit up to which a run of the job starts another batch.
	 */
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
	 * @var OptionLock
	 */
	protected $lock;

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	/**
	 * @var CorreiosAutomatico
	 */
	protected $correios;

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

	public function __construct( FiscalDataSource $source, FiscalImportPlanner $planner, ProductNcmStore $store, FiscalImportRunRepository $runs, OptionLock $lock, BackgroundQueue $queue, CorreiosAutomatico $correios, int $batchPosts = FiscalImportRunner::BATCH_POSTS, int $jobSeconds = FiscalImportRunner::TIME_BUDGET ) {
		$this->source = $source;
		$this->planner = $planner;
		$this->store = $store;
		$this->runs = $runs;
		$this->lock = $lock;
		$this->queue = $queue;
		$this->correios = $correios;
		$this->batchPosts = max( 1, $batchPosts );
		$this->jobSeconds = max( 0, $jobSeconds );
	}

	/**
	 * Queues a scan unless there is a result or one is under way.
	 */
	public function ensureScheduled(): void {
		if ( $this->runs->scan() !== null || $this->runs->state()['scan'] !== null ) {
			return;
		}

		$this->queue->schedule( FiscalImportJobs::SCAN_HOOK, [], time(), true );
	}

	/**
	 * Drops the result and the scan under way, and scans again from the first product.
	 */
	public function restart(): void {
		$this->runs->forgetScan();
		$this->runs->update( function ( array $state ): array {
			$state['scan'] = null;

			return $state;
		} );

		$this->queue->schedule( FiscalImportJobs::SCAN_HOOK, [], time(), true );
	}

	/**
	 * Progress of the scan under way, null when none.
	 *
	 * @return array{processed: int, total: int}|null
	 */
	public function progress(): ?array {
		$scan = $this->runs->state()['scan'];

		return $scan === null ? null : [
			'processed' => (int) ( $scan['processed'] ?? 0 ),
			'total' => max( (int) ( $scan['total'] ?? 0 ), (int) ( $scan['processed'] ?? 0 ) ),
		];
	}

	/**
	 * Job: batches for about FiscalImportRunner::TIME_BUDGET seconds, then the next job.
	 */
	public function handle(): void {
		$budget = new JobBudget( $this->jobSeconds, self::JOB_MEMORY_SHARE );

		do {
			$result = $this->runBatch();

			if ( $result !== self::CONTINUE ) {
				break;
			}

			JobBudget::releaseRuntimeCache();
		} while ( ! $budget->exhausted() );

		if ( $result !== self::DONE ) {
			$this->queueNext( $result === self::LOCKED ? self::LOCKED_DELAY : self::NEXT_DELAY );
		}
	}

	/**
	 * One batch of the scan.
	 *
	 * @return string CONTINUE, DONE or LOCKED.
	 */
	public function runBatch(): string {
		if ( ! $this->lock->acquire( FiscalImportRunRepository::LOCK, FiscalImportRunRepository::LOCK_TTL ) ) {
			return self::LOCKED;
		}

		try {
			$scan = $this->runs->state()['scan'];
			$fresh = $scan === null;
			$scan = $fresh ? $this->start() : $this->normalize( $scan );
			$ids = $this->source->nextBatch( (int) $scan['cursor'], $this->batchPosts );

			if ( ! $ids ) {
				$this->complete( $scan, $fresh );
				return self::DONE;
			}

			$this->save( $scan['id'], $this->plan( $scan, $ids ), $fresh );

			return self::CONTINUE;
		} finally {
			$this->lock->release( FiscalImportRunRepository::LOCK );
		}
	}

	/**
	 * @param int[] $ids
	 */
	private function plan( array $scan, array $ids ): array {
		$choices = FiscalImportChoices::defaults( $this->correios->isActive() );
		$global = $this->source->globalNcm();
		$budget = new JobBudget( FiscalImportRunner::TIME_BUDGET, FiscalImportRunner::MEMORY_SHARE );

		foreach ( $ids as $index => $id ) {
			if ( $index > 0 && $budget->exhausted() ) {
				break;
			}

			$scan['cursor'] = $id;
			++$scan['processed'];
			$record = $this->source->read( $id );

			if ( $record === null ) {
				continue;
			}

			$decision = $this->planner->plan( $record, $this->store->stateOf( $id ), $choices, $global );
			$scan['counts'] = FiscalImportCounts::add( $scan['counts'], $decision, $record, $decision->writes() );

			if ( $decision->writes() ) {
				$scan['targets'][ $decision->target() ] = ( $scan['targets'][ $decision->target() ] ?? 0 ) + 1;
			}

			if ( $this->hasOwnNcm( $record ) ) {
				++$scan['productsWithNcm'];
			}

			foreach ( $record->categories() as $term_id => $category ) {
				if ( NcmCode::normalize( (string) $category['ncm'] ) !== '' ) {
					$scan['categories'][ (int) $term_id ] = 1;
				}
			}
		}

		return $scan;
	}

	/**
	 * The product or one of its variations has an NCM in the other plugin.
	 */
	private function hasOwnNcm( FiscalRecord $record ): bool {
		if ( NcmCode::normalize( $record->productNcm() ) !== '' ) {
			return true;
		}

		foreach ( $record->variations() as $variation ) {
			if ( NcmCode::normalize( (string) $variation['ncm'] ) !== '' ) {
				return true;
			}
		}

		return false;
	}

	private function start(): array {
		return [
			'id' => bin2hex( random_bytes( 4 ) ),
			'cursor' => 0,
			'processed' => 0,
			'total' => $this->source->countProducts(),
			'counts' => FiscalImportCounts::blank(),
			'targets' => [],
			'productsWithNcm' => 0,
			'categories' => [],
			'startedAt' => time(),
			'heartbeat' => time(),
		];
	}

	private function normalize( array $scan ): array {
		return [
			'id' => (string) ( $scan['id'] ?? '' ),
			'cursor' => max( 0, (int) ( $scan['cursor'] ?? 0 ) ),
			'processed' => max( 0, (int) ( $scan['processed'] ?? 0 ) ),
			'total' => max( 0, (int) ( $scan['total'] ?? 0 ) ),
			'counts' => FiscalImportCounts::normalize( $scan['counts'] ?? [] ),
			'targets' => isset( $scan['targets'] ) && is_array( $scan['targets'] ) ? $scan['targets'] : [],
			'productsWithNcm' => max( 0, (int) ( $scan['productsWithNcm'] ?? 0 ) ),
			'categories' => isset( $scan['categories'] ) && is_array( $scan['categories'] ) ? $scan['categories'] : [],
			'startedAt' => (int) ( $scan['startedAt'] ?? time() ),
			'heartbeat' => (int) ( $scan['heartbeat'] ?? time() ),
		];
	}

	/**
	 * Saves the progress (null: clears the scan under way), unless the scan was restarted meanwhile.
	 *
	 * @param string     $id    Id of the scan of this batch.
	 * @param array|null $scan  Progress to keep.
	 * @param bool       $fresh The scan started in this batch.
	 *
	 * @return bool Whether it was saved.
	 */
	private function save( string $id, ?array $scan, bool $fresh ): bool {
		$saved = false;

		$this->runs->update( function ( array $state ) use ( $id, $scan, $fresh, &$saved ): array {
			$current = $state['scan'];
			$ours = $fresh ? $current === null : $current !== null && ( $current['id'] ?? '' ) === $id;

			if ( ! $ours ) {
				return $state;
			}

			$saved = true;
			$state['scan'] = $scan;

			if ( $scan !== null ) {
				$state['scan']['heartbeat'] = time();
			}

			return $state;
		} );

		return $saved;
	}

	/**
	 * Keeps the result of a finished scan (FiscalImportScan) and clears the scan under way.
	 */
	private function complete( array $scan, bool $fresh ): void {
		$counts = FiscalImportCounts::withCatchAll( $scan['counts'], $scan['targets'] );

		if ( ! $fresh && ! $this->save( $scan['id'], null, false ) ) {
			return;
		}

		$this->runs->saveScan( [
			'candidates' => $counts['fill'],
			'productsWithNcm' => $scan['productsWithNcm'],
			'categoriesWithNcm' => count( $scan['categories'] ),
			'products' => $scan['processed'],
			'counts' => $counts,
			'finishedAt' => time(),
		] );
	}

	/**
	 * Queues the next run from inside a run (not unique: the running job counts as one), unless one is pending.
	 */
	private function queueNext( int $delay ): void {
		if ( $this->queue->pendingCount( FiscalImportJobs::SCAN_HOOK ) > 0 ) {
			return;
		}

		$this->queue->schedule( FiscalImportJobs::SCAN_HOOK, [], time() + $delay );
	}
}
