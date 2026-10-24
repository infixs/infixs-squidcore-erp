<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Support\BackgroundQueue;
use Infixs\SquidCore\Support\JobBudget;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, once and in the background, the simplified DANFE snapshot of the invoices authorized before the store could
 * print it: the ones of the last 45 days, on orders still to ship (not completed, cancelled, refunded or failed).
 *
 * Starts on the first admin page load where Correios Automático prints the DANFE, so it runs whichever plugin was
 * updated first. Each run reads batches of 10 orders, one XML at a time and with a short wait for each, while its
 * budget lasts (checked before every order after the first, so a slow SquidCore stops the run early), then hands over
 * to the next run; it ends when no order is left.
 *
 * @package Infixs\SquidCore
 * @since   1.4.0
 */
class DanfeBackfill {

	/**
	 * State of the backfill: status (running or done), since, startedAt, finishedAt, built, failed and the ids of
	 * the orders left out (invoices that get no DANFE).
	 */
	public const OPTION = 'infixs_squidcore_danfe_backfill';

	public const RUNNING = 'running';

	public const DONE = 'done';

	public const WINDOW_DAYS = 45;

	private const BATCH_SIZE = 10;

	/**
	 * Seconds before the next run of an unfinished backfill.
	 */
	private const NEXT_RUN_DELAY = 30;

	private const BUDGET_SECONDS = 20;

	private const BUDGET_MEMORY_SHARE = 0.5;

	/**
	 * Order statuses whose parcel is no longer printed.
	 */
	private const SKIPPED_STATUSES = [ 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' ];

	/**
	 * @var InvoiceRepository
	 */
	protected $repository;

	/**
	 * @var InvoiceService
	 */
	protected $invoices;

	/**
	 * @var DanfeSnapshotService
	 */
	protected $snapshots;

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	public function __construct( InvoiceRepository $repository, InvoiceService $invoices, DanfeSnapshotService $snapshots, BackgroundQueue $queue ) {
		$this->repository = $repository;
		$this->invoices = $invoices;
		$this->snapshots = $snapshots;
		$this->queue = $queue;
	}

	/**
	 * Starts the backfill once, or queues the next run of one a missing job left unfinished. Cheap once done: one
	 * autoloaded option. Admin page loads only: the heartbeat and other admin-ajax calls skip it.
	 */
	public function maybeStart(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		$state = $this->state();

		if ( ( $state['status'] ?? '' ) === self::DONE || ! $this->snapshots->canFetch() ) {
			return;
		}

		if ( ! $state ) {
			$this->save( [
				'status' => self::RUNNING,
				'since' => time() - self::WINDOW_DAYS * DAY_IN_SECONDS,
				'startedAt' => time(),
				'built' => 0,
				'failed' => 0,
				'skipped' => [],
			] );
		}

		$this->queue->schedule( DanfeSnapshotJobs::BACKFILL_HOOK, [], time(), true );
	}

	/**
	 * One run: batches while the budget lasts, then the next run is queued, unless no order is left.
	 */
	public function run(): void {
		$state = $this->state();

		// A store that stopped meeting the conditions resumes on a later admin page load.
		if ( ( $state['status'] ?? '' ) !== self::RUNNING || ! $this->snapshots->canFetch() ) {
			return;
		}

		$budget = new JobBudget( self::BUDGET_SECONDS, self::BUDGET_MEMORY_SHARE );

		do {
			$orders = $this->repository->findAuthorizedSince( (int) $state['since'], $this->statuses(), self::BATCH_SIZE, (array) $state['skipped'], DanfeSnapshotRepository::META_KEY );

			if ( ! $orders ) {
				$this->save( [
					'status' => self::DONE,
					'since' => (int) $state['since'],
					'startedAt' => (int) $state['startedAt'],
					'finishedAt' => time(),
					'built' => (int) $state['built'],
					'failed' => (int) $state['failed'],
				] );

				return;
			}

			$state = $this->readBatch( $orders, $state, $budget );
			$this->save( $state );
			JobBudget::releaseRuntimeCache();
		} while ( ! $budget->exhausted() );

		$this->queue->schedule( DanfeSnapshotJobs::BACKFILL_HOOK, [], time() + self::NEXT_RUN_DELAY );
	}

	/**
	 * @return array The saved state, empty before the backfill started.
	 */
	public function state(): array {
		$state = get_option( self::OPTION, [] );

		return is_array( $state ) ? $state : [];
	}

	/**
	 * Reads the snapshot of each order of a batch while the budget lasts; an order whose invoice gets no DANFE is left
	 * out of the next ones. The first order is always read, so a run that starts over its budget still moves on.
	 *
	 * @param \WC_Order[] $orders
	 */
	private function readBatch( array $orders, array $state, JobBudget $budget ): array {
		foreach ( array_values( $orders ) as $index => $order ) {
			if ( $index > 0 && $budget->exhausted() ) {
				break;
			}

			$record = $this->invoices->find( $order );

			if ( ! $record || ! $this->snapshots->isEligible( $record ) ) {
				$state['skipped'][] = $order->get_id();
				continue;
			}

			$snapshot = $this->snapshots->buildForBackfill( $order, $record );
			$state[ $snapshot->isReady() ? 'built' : 'failed' ]++;
		}

		return $state;
	}

	/**
	 * Order statuses whose orders are read.
	 *
	 * @return string[]
	 */
	private function statuses(): array {
		$skipped = array_map( function ( $status ) {
			return 'wc-' . $status;
		}, self::SKIPPED_STATUSES );

		return array_values( array_diff( array_keys( wc_get_order_statuses() ), $skipped ) );
	}

	private function save( array $state ): void {
		update_option( self::OPTION, $state, true );
	}
}
