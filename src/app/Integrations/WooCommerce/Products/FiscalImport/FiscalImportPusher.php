<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Integrations\WooCommerce\SyncDispatcher;
use Infixs\SquidCore\Services\ConnectionService;
use Infixs\SquidCore\Services\SyncService;
use Infixs\SquidCore\Support\BackgroundQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Takes the NCM the runs wrote (or restored) to SquidCore: every product whose history entry was not queued yet,
 * whatever run wrote it, goes through the sync queue, block after block, behind the day-to-day pushes of the store (a
 * higher priority number) and never more than QUEUE_LIMIT waiting at once. A run that ends while an earlier push is
 * still going (or waiting) only starts the push over, so no product of the earlier run is left behind. Trashed
 * products are never sent.
 *
 * Only a connected store with the catalog sync on sends anything; otherwise the push waits in a flag, and goes out by
 * itself on the first admin page after the store connects or turns the catalog sync on.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportPusher {

	public const BLOCKED_NOT_CONNECTED = 'not_connected';

	public const BLOCKED_CATALOG_OFF = 'catalog_off';

	/**
	 * Pushes waiting in the sync queue above which no block is queued.
	 */
	public const QUEUE_LIMIT = 200;

	/**
	 * Priority of the pushes of the import: after the store's own pushes (10).
	 */
	public const PRIORITY = SyncDispatcher::BULK_PRIORITY;

	public const RUNNING = 'running';

	public const DONE = 'done';

	private const PUSH_DELAY = 5;

	private const NEXT_DELAY = 5;

	private const BUSY_DELAY = 60;

	private const NOT_SENT = [ 'trash', 'auto-draft' ];

	/**
	 * @var FiscalImportRunRepository
	 */
	protected $runs;

	/**
	 * @var FiscalImportJournal
	 */
	protected $journal;

	/**
	 * @var SyncDispatcher
	 */
	protected $dispatcher;

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	/**
	 * @var SyncService
	 */
	protected $sync;

	public function __construct( FiscalImportRunRepository $runs, FiscalImportJournal $journal, SyncDispatcher $dispatcher, BackgroundQueue $queue, ConnectionService $connection, SyncService $sync ) {
		$this->runs = $runs;
		$this->journal = $journal;
		$this->dispatcher = $dispatcher;
		$this->queue = $queue;
		$this->connection = $connection;
		$this->sync = $sync;
	}

	/**
	 * Why nothing can be sent now: BLOCKED_* constant, null when the push can go.
	 */
	public function blockedBy(): ?string {
		if ( ! $this->connection->isConnected() ) {
			return self::BLOCKED_NOT_CONNECTED;
		}

		return $this->sync->options()['catalog'] ? null : self::BLOCKED_CATALOG_OFF;
	}

	/**
	 * Sends the NCM written by the run (and by any earlier run not sent yet) now, or as soon as the store can.
	 *
	 * @return array{queued: bool, pending: bool, blockedBy: string|null} TS FiscalImportPushResult.
	 */
	public function request( string $runId ): array {
		$blocked = $this->blockedBy();

		if ( $blocked !== null ) {
			$this->runs->setPushPending( $runId );

			return [
				'queued' => false,
				'pending' => true,
				'blockedBy' => $blocked,
			];
		}

		$this->start( $runId );

		return [
			'queued' => true,
			'pending' => false,
			'blockedBy' => null,
		];
	}

	/**
	 * Queues the push: from the first product, so the products of this run and of any earlier one not sent yet all go.
	 * A push under way keeps its count of queued products.
	 */
	public function start( string $runId ): void {
		$this->runs->clearPushPending();
		$this->runs->update( function ( array $state ) use ( $runId ): array {
			$push = $state['push'];
			$running = $push && ( $push['status'] ?? '' ) === self::RUNNING;

			$state['push'] = [
				'id' => bin2hex( random_bytes( 4 ) ),
				'runId' => $runId,
				'cursor' => 0,
				'queued' => $running ? (int) ( $push['queued'] ?? 0 ) : 0,
				'status' => self::RUNNING,
				'heartbeat' => time(),
			];

			return $state;
		} );

		$this->queue->schedule( FiscalImportJobs::PUSH_HOOK, [], time(), true );
	}

	/**
	 * A push waiting for the store goes out once it is connected with the catalog sync on. Costs the read of an
	 * autoloaded option while nothing waits.
	 */
	public function checkPending(): void {
		$run_id = $this->runs->pushPending();

		if ( $run_id === null || $this->blockedBy() !== null ) {
			return;
		}

		$this->start( $run_id );
	}

	/**
	 * Job: queues the next block of products, then the next run of the job.
	 */
	public function handle(): void {
		$push = $this->runs->state()['push'];

		if ( ! $push || ( $push['status'] ?? '' ) !== self::RUNNING ) {
			return;
		}

		$push_id = (string) ( $push['id'] ?? '' );

		if ( $this->blockedBy() !== null ) {
			$this->runs->setPushPending( (string) $push['runId'] );
			return;
		}

		$room = self::QUEUE_LIMIT - $this->dispatcher->pendingCount();

		if ( $room <= 0 ) {
			$this->saveProgress( $push_id, (int) $push['cursor'], 0, [], false );
			$this->queueNext( self::BUSY_DELAY );
			return;
		}

		$ids = $this->journal->unpushedIds( (int) $push['cursor'], $room );

		if ( ! $ids ) {
			$this->saveProgress( $push_id, (int) $push['cursor'], 0, [], true );
			return;
		}

		_prime_post_caches( $ids, false, true );

		$queued = 0;
		$filled = [];

		foreach ( $ids as $id ) {
			$entry = $this->journal->entry( $id );
			$status = get_post_status( $id );

			if ( $entry === null || ! $status || in_array( $status, self::NOT_SENT, true ) ) {
				continue;
			}

			$this->dispatcher->dispatch( 'product.updated', $id, self::PUSH_DELAY, self::PRIORITY );
			$this->journal->markPushed( $id, time() );
			++$queued;

			if ( FiscalImportJournal::wasFill( $entry ) ) {
				$filled[ $entry['run'] ] = ( $filled[ $entry['run'] ] ?? 0 ) + 1;
			}
		}

		$this->saveProgress( $push_id, (int) end( $ids ), $queued, $filled, false );
		$this->queueNext( self::NEXT_DELAY );
	}

	/**
	 * @return array{pending: bool, queued: int, queue: int, running: bool, blockedBy: string|null} TS FiscalImportPush.
	 */
	public function overview(): array {
		$push = $this->runs->state()['push'];
		$pending = $this->runs->pushPending() !== null;

		return [
			'pending' => $pending,
			'queued' => $push ? (int) ( $push['queued'] ?? 0 ) : 0,
			'queue' => $this->dispatcher->pendingCount(),
			'running' => ! $pending && $push !== null && ( $push['status'] ?? '' ) === self::RUNNING,
			'blockedBy' => $pending ? $this->blockedBy() : null,
		];
	}

	/**
	 * Saves the progress of the push. When another push took its place meanwhile (a new run ended), only the counts
	 * are added: the new push goes from the first product again. The products that got their first NCM in SquidCore
	 * are added to the import that wrote them.
	 *
	 * @param array<string, int> $filled Run id => products.
	 */
	private function saveProgress( string $pushId, int $cursor, int $queued, array $filled, bool $done ): void {
		$this->runs->update( function ( array $state ) use ( $pushId, $cursor, $queued, $filled, $done ): array {
			if ( $state['push'] ) {
				$state['push']['queued'] = (int) ( $state['push']['queued'] ?? 0 ) + $queued;

				if ( (string) ( $state['push']['id'] ?? '' ) === $pushId ) {
					$state['push']['cursor'] = $cursor;
					$state['push']['heartbeat'] = time();
					$state['push']['status'] = $done ? self::DONE : self::RUNNING;
				}
			}

			foreach ( [ 'run', 'last' ] as $key ) {
				$run = $state[ $key ];

				if ( $run !== null && $run['mode'] === FiscalImportRun::MODE_APPLY && isset( $filled[ $run['id'] ] ) ) {
					$state[ $key ]['pushedFilled'] += $filled[ $run['id'] ];
				}
			}

			return $state;
		} );
	}

	/**
	 * Queues the next run from inside a run (not unique: the running job counts as one), unless one is pending.
	 */
	private function queueNext( int $delay ): void {
		if ( $this->queue->pendingCount( FiscalImportJobs::PUSH_HOOK ) > 0 ) {
			return;
		}

		$this->queue->schedule( FiscalImportJobs::PUSH_HOOK, [], time() + $delay );
	}
}
