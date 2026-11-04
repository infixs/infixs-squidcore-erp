<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceEligibility;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRecord;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Integrations\WooCommerce\SyncDispatcher;
use Infixs\SquidCore\Services\ConnectionService;
use Infixs\SquidCore\Support\BackgroundQueue;
use Infixs\SquidCore\Support\JobBudget;

defined( 'ABSPATH' ) || exit;

/**
 * "Issue the pending invoices": after a plan change, issues again, in the background, every invoice that failed for
 * the plan limit, instead of one "Try again" per order.
 *
 * The run takes the orders failed for the plan limit when it starts, oldest first, and issues them one at a time as
 * "Try again" does, at a low priority, while its budget lasts; the next run goes on a few seconds later. A new 402
 * stops it at once, of the plan limit or any other refusal of the whole account (subscription, plan without
 * invoices), as does a SquidCore that does not answer or a store no longer connected: every order left, the one tried
 * included, stays failed for the limit (no request is made for the others). An order that is no longer failed for the
 * limit (issued meanwhile) is passed over; one whose sale ended (cancelled, refunded, failed, trashed) or that
 * something now rules out (an invoice another plugin issued meanwhile, as the automatic emission asks right before it
 * runs) is skipped and leaves the pending invoices.
 *
 * @package Infixs\SquidCore
 * @since   1.5.0
 */
class PlanLimitRetry {

	public const HOOK = 'infixs_squidcore_invoice_plan_limit_retry';

	/**
	 * State of the last run: status, ids (orders still to go through, while running), position, total, done,
	 * stoppedBy, startedAt, updatedAt, finishedAt.
	 */
	public const OPTION = 'infixs_squidcore_plan_limit_retry';

	public const IDLE = 'idle';

	public const RUNNING = 'running';

	public const DONE = 'done';

	public const STOPPED = 'stopped';

	/**
	 * The run stopped on a new 402 of the plan limit.
	 */
	public const STOPPED_PLAN_LIMIT = 'plan_limit';

	/**
	 * The run stopped because SquidCore refused every invoice of the account (any other 402).
	 */
	public const STOPPED_ACCOUNT = 'account';

	/**
	 * The run stopped because SquidCore did not answer or the store is no longer connected.
	 */
	public const STOPPED_UNAVAILABLE = 'unavailable';

	/**
	 * Error code of start() when no order is failed for the plan limit.
	 */
	public const NOTHING_TO_ISSUE = 'plan_limit_nothing_to_issue';

	/**
	 * Below the order pushes and the automatic emissions, like the bulk product updates.
	 */
	public const PRIORITY = SyncDispatcher::BULK_PRIORITY;

	/**
	 * Seconds before the next run of an unfinished run.
	 */
	private const NEXT_RUN_DELAY = 5;

	private const BUDGET_SECONDS = 20;

	private const BUDGET_MEMORY_SHARE = 0.5;

	/**
	 * A run without a job and untouched for this long was cut short (its job removed): a new one may start.
	 */
	private const STALE_AFTER = 15 * MINUTE_IN_SECONDS;

	/**
	 * @var InvoiceRepository
	 */
	protected $repository;

	/**
	 * @var InvoiceService
	 */
	protected $invoices;

	/**
	 * @var InvoiceEligibility
	 */
	protected $eligibility;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	/**
	 * @param InvoiceEligibility $eligibility Whether the sale of an order ended, and why the emission no longer runs
	 *                                        for it.
	 */
	public function __construct( InvoiceRepository $repository, InvoiceService $invoices, InvoiceEligibility $eligibility, ConnectionService $connection, BackgroundQueue $queue ) {
		$this->repository = $repository;
		$this->invoices = $invoices;
		$this->eligibility = $eligibility;
		$this->connection = $connection;
		$this->queue = $queue;
	}

	/**
	 * The job handler must be registered on every request (front, admin, cron).
	 */
	public function registerHandlers(): void {
		add_action( self::HOOK, [ $this, 'handleRun' ] );
	}

	public function handleRun(): void {
		if ( function_exists( 'wc_get_order' ) ) {
			$this->run();
		}
	}

	/**
	 * Queues the re-emission of every invoice failed for the plan limit. A run already on its way is kept as it is.
	 *
	 * @return array|\WP_Error status() of the run, or an error when there is nothing to issue or the store is not
	 *                         connected.
	 */
	public function start() {
		if ( $this->isRunning() ) {
			return $this->status();
		}

		if ( ! $this->connection->isConnected() ) {
			return new \WP_Error( 'squidcore_not_connected', __( 'SquidCore is not connected. Connect the store in the plugin settings and try again.', 'infixs-squidcore-erp' ), [ 'status' => 409 ] );
		}

		$ids = $this->repository->findIdsByFailure( InvoiceRecord::FAILURE_PLAN_LIMIT );

		if ( ! $ids ) {
			return new \WP_Error( self::NOTHING_TO_ISSUE, __( 'No invoice is waiting for the plan limit.', 'infixs-squidcore-erp' ), [ 'status' => 409 ] );
		}

		$this->save( [
			'status' => self::RUNNING,
			'ids' => $ids,
			'position' => 0,
			'total' => count( $ids ),
			'done' => 0,
			'stoppedBy' => '',
			'startedAt' => time(),
			'updatedAt' => time(),
			'finishedAt' => 0,
		] );

		$this->queue->schedule( self::HOOK, [], time(), true, self::PRIORITY );

		return $this->status();
	}

	/**
	 * One run: the orders one at a time while the budget lasts (the first one always), then the next run is queued.
	 */
	public function run(): void {
		$state = $this->state();

		if ( $state['status'] !== self::RUNNING ) {
			return;
		}

		$budget = new JobBudget( self::BUDGET_SECONDS, self::BUDGET_MEMORY_SHARE );
		$ids = array_values( array_map( 'intval', (array) $state['ids'] ) );
		$tried = 0;

		while ( $state['position'] < count( $ids ) ) {
			if ( $tried > 0 && $budget->exhausted() ) {
				break;
			}

			// Checked before every order: a store disconnected halfway would fail them all for good.
			if ( ! $this->connection->isConnected() ) {
				$this->finish( $state, self::STOPPED, self::STOPPED_UNAVAILABLE );
				return;
			}

			++$tried;
			$outcome = $this->issue( $ids[ $state['position'] ] );
			++$state['position'];
			++$state['done'];

			if ( $outcome !== null ) {
				$this->finish( $state, self::STOPPED, $outcome );
				return;
			}

			$state['updatedAt'] = time();
			$this->save( $state );
			JobBudget::releaseRuntimeCache();
		}

		if ( $state['position'] >= count( $ids ) ) {
			$this->finish( $state, self::DONE );
			return;
		}

		$this->queue->schedule( self::HOOK, [], time() + self::NEXT_RUN_DELAY, false, self::PRIORITY );
	}

	/**
	 * The last run as the screens show it.
	 *
	 * @return array{status: string, total: int, done: int, stoppedBy: string|null, finishedAt: string|null}
	 */
	public function status(): array {
		$state = $this->state();
		$status = $state['status'];

		if ( $status === self::RUNNING && ! $this->isRunning() ) {
			$status = self::STOPPED;
			$state['stoppedBy'] = self::STOPPED_UNAVAILABLE;
		}

		return [
			'status' => $status,
			'total' => (int) $state['total'],
			'done' => min( (int) $state['done'], (int) $state['total'] ),
			'stoppedBy' => $status === self::STOPPED && $state['stoppedBy'] !== '' ? (string) $state['stoppedBy'] : null,
			'finishedAt' => $state['finishedAt'] > 0 ? gmdate( 'c', (int) $state['finishedAt'] ) : null,
		];
	}

	/**
	 * Whether a run is on its way: running with its job queued, or touched a moment ago (its job may be the one
	 * running now).
	 */
	public function isRunning(): bool {
		$state = $this->state();

		if ( $state['status'] !== self::RUNNING ) {
			return false;
		}

		return time() - (int) $state['updatedAt'] < self::STALE_AFTER || $this->queue->isScheduled( self::HOOK, [] );
	}

	/**
	 * Issues one order again, as "Try again" does, when it is still failed for the plan limit and still gets an
	 * invoice.
	 *
	 * @return string|null Why the run must stop (STOPPED_PLAN_LIMIT, STOPPED_ACCOUNT, STOPPED_UNAVAILABLE), null to go
	 *                     on.
	 */
	private function issue( int $orderId ): ?string {
		$order = wc_get_order( $orderId );
		$refused = $order instanceof \WC_Order ? $this->invoices->find( $order ) : null;

		if ( ! $order instanceof \WC_Order || ! $refused || ! $refused->isPlanLimitFailure() ) {
			return null;
		}

		$skip_reason = $this->skipReason( $order );

		if ( $skip_reason !== null ) {
			$this->invoices->skip( $order, $skip_reason );
			return null;
		}

		$record = $this->invoices->emit( $order, InvoiceService::TRIGGER_MANUAL );

		// Another request holds the invoice (an emission on its way): nothing to do here.
		if ( is_wp_error( $record ) ) {
			return null;
		}

		if ( $record->isPlanLimitFailure() ) {
			return self::STOPPED_PLAN_LIMIT;
		}

		if ( ! $record->isAccountFailure() && ! $record->isTransientFailure() ) {
			return null;
		}

		// SquidCore said nothing about this order: it stays among the ones the plan limit refused.
		$this->invoices->restorePlanLimitFailure( $order, $refused );

		return $record->isAccountFailure() ? self::STOPPED_ACCOUNT : self::STOPPED_UNAVAILABLE;
	}

	/**
	 * Why the order no longer gets its invoice: its sale ended, or what the automatic emission asks right before it
	 * runs rules it out (an invoice another plugin issued meanwhile, for example).
	 *
	 * @return string|null Message for the order note, null when the invoice is issued.
	 */
	private function skipReason( \WC_Order $order ): ?string {
		if ( $this->eligibility->isSaleEnded( $order ) ) {
			return PlanLimitLabels::saleEnded( $order->get_status() );
		}

		return $this->eligibility->emitTimeSkipReason( $order );
	}

	private function finish( array $state, string $status, string $stoppedBy = '' ): void {
		$state['status'] = $status;
		$state['stoppedBy'] = $stoppedBy;
		$state['ids'] = [];
		$state['updatedAt'] = time();
		$state['finishedAt'] = time();
		$this->save( $state );
	}

	/**
	 * @return array{status: string, ids: int[], position: int, total: int, done: int, stoppedBy: string, startedAt: int, updatedAt: int, finishedAt: int}
	 */
	private function state(): array {
		$state = get_option( self::OPTION, [] );

		return array_merge( [
			'status' => self::IDLE,
			'ids' => [],
			'position' => 0,
			'total' => 0,
			'done' => 0,
			'stoppedBy' => '',
			'startedAt' => 0,
			'updatedAt' => 0,
			'finishedAt' => 0,
		], is_array( $state ) ? $state : [] );
	}

	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
