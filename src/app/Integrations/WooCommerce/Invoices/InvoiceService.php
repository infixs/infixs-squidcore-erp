<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

use Infixs\SquidCore\Integrations\WooCommerce\ActivityRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit\PlanLimitLabels;
use Infixs\SquidCore\Integrations\WooCommerce\SyncDispatcher;
use Infixs\SquidCore\Services\ConnectionService;
use Infixs\SquidCore\Support\ApiErrorMessage;
use Infixs\SquidCore\Support\BackgroundQueue;
use Infixs\SquidCore\Support\OptionLock;
use Infixs\SquidCore\Support\SquidCoreUrls;

defined( 'ABSPATH' ) || exit;

/**
 * Issues, follows and cancels the invoice (NF-e) of an order through SquidCore.
 *
 * Every change of the invoice record goes through here: it writes the order note and fires the public actions.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceService {

	public const TRIGGER_AUTOMATIC = 'automatic';

	public const TRIGGER_MANUAL = 'manual';

	/**
	 * Waits before each new automatic attempt after a temporary failure, in seconds.
	 */
	public const RETRY_DELAYS = [ 60, 300, 900, 3600 ];

	/**
	 * Waits between the status checks of an invoice SEFAZ is still processing, in seconds (about two days in all).
	 */
	public const POLL_DELAYS = [ 30, 60, 120, 300, 600, 1800, 3600, 7200, 21600, 43200, 86400 ];

	/**
	 * States that wait for the store owner: pending items, rejected, denied or failed.
	 */
	public const STATES_NEEDING_ACTION = [ InvoiceRecord::BLOCKED, InvoiceRecord::ERROR, InvoiceRecord::DENIED, InvoiceRecord::FAILED ];

	/**
	 * States of an emission on its way.
	 */
	public const STATES_IN_PROGRESS = [ InvoiceRecord::SCHEDULED, InvoiceRecord::EMITTING, InvoiceRecord::PROCESSING ];

	public const JUSTIFICATION_MIN_LENGTH = 15;

	public const JUSTIFICATION_MAX_LENGTH = 255;

	/**
	 * A lock older than this (seconds) belongs to a request that died.
	 */
	private const LOCK_TTL = 120;

	/**
	 * @var InvoiceApi
	 */
	protected $api;

	/**
	 * @var InvoiceRepository
	 */
	protected $repository;

	/**
	 * @var InvoiceSettings
	 */
	protected $settings;

	/**
	 * @var InvoiceEligibility
	 */
	protected $eligibility;

	/**
	 * @var SyncDispatcher
	 */
	protected $dispatcher;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	/**
	 * @var InvoiceProductSync
	 */
	protected $productSync;

	/**
	 * @var ActivityRepository
	 */
	protected $activity;

	/**
	 * @var OptionLock
	 */
	protected $lock;

	/**
	 * @var SquidCoreUrls
	 */
	protected $urls;

	/**
	 * @param ActivityRepository $activity Counters of the activity chart: the emissions that stop waiting on anyone
	 *                                     but the store owner, and the order pushes the emission gave up on.
	 * @param OptionLock         $lock     Per-order lock shared by every request that changes the invoice.
	 * @param SquidCoreUrls      $urls     The plans of the panel, linked from the note of an emission the plan limit
	 *                                     refused.
	 */
	public function __construct(
		InvoiceApi $api,
		InvoiceRepository $repository,
		InvoiceSettings $settings,
		InvoiceEligibility $eligibility,
		SyncDispatcher $dispatcher,
		ConnectionService $connection,
		BackgroundQueue $queue,
		InvoiceProductSync $productSync,
		ActivityRepository $activity,
		OptionLock $lock,
		SquidCoreUrls $urls
	) {
		$this->api = $api;
		$this->repository = $repository;
		$this->settings = $settings;
		$this->eligibility = $eligibility;
		$this->dispatcher = $dispatcher;
		$this->connection = $connection;
		$this->queue = $queue;
		$this->productSync = $productSync;
		$this->activity = $activity;
		$this->lock = $lock;
		$this->urls = $urls;
	}

	public function find( \WC_Order $order ): ?InvoiceRecord {
		return $this->repository->find( $order );
	}

	/**
	 * Local summary for the dashboard; never calls the API.
	 *
	 * @return array{enabled: bool, delayHours: int, waiting: int, inProgress: int}
	 */
	public function overview(): array {
		return [
			'enabled' => $this->settings->isEnabled(),
			'delayHours' => $this->settings->delayHours(),
			'waiting' => $this->repository->countPending( self::STATES_NEEDING_ACTION ),
			'inProgress' => $this->repository->countByStates( self::STATES_IN_PROGRESS ),
		];
	}

	/**
	 * Page of the invoices list of SquidCore.
	 *
	 * @param array $params page, perPage, status, search.
	 *
	 * @return array|\WP_Error {data, currentPage, perPage, total, lastPage}
	 */
	public function listRemote( array $params ) {
		$response = $this->api->interactive()->list( $params );

		return is_wp_error( $response ) ? ApiErrorMessage::invoiceError( $response, $this->connection->invoicePermission() ) : $response;
	}

	/**
	 * Most recent orders whose invoice waits for the store owner.
	 *
	 * @return \WC_Order[]
	 */
	public function ordersNeedingAction( int $limit ): array {
		return $this->repository->findPending( self::STATES_NEEDING_ACTION, $limit );
	}

	/**
	 * Queues the automatic emission.
	 *
	 * @param int $timestamp Unix time of the emission.
	 */
	public function schedule( \WC_Order $order, int $timestamp ): InvoiceRecord {
		$record = ( $this->repository->find( $order ) ?? new InvoiceRecord() )->setScheduled( $timestamp );
		$this->repository->save( $order, $record );

		$this->queue->schedule( InvoiceJobs::EMIT_HOOK, [ $order->get_id() ], $timestamp, true );

		$order->add_order_note( sprintf(
			/* translators: %s: date and time of the emission. */
			__( 'SquidCore: invoice (NF-e) scheduled for %s.', 'infixs-squidcore-erp' ),
			InvoiceLabels::dateTime( $timestamp )
		) );

		/**
		 * Fires when the automatic emission of an order invoice is queued.
		 *
		 * @since 1.0.0
		 *
		 * @param int $order_id  Order id.
		 * @param int $timestamp Unix time of the emission.
		 */
		do_action( 'infixs_squidcore_invoice_scheduled', $order->get_id(), $timestamp );

		return $record;
	}

	/**
	 * Records that the order does not get an invoice automatically.
	 */
	public function skip( \WC_Order $order, string $reason ): InvoiceRecord {
		$this->queue->unschedule( InvoiceJobs::EMIT_HOOK, [ $order->get_id() ] );

		$record = ( $this->repository->find( $order ) ?? new InvoiceRecord() )->setState( InvoiceRecord::SKIPPED, $reason );
		$this->repository->save( $order, $record );

		$order->add_order_note( 'SquidCore: ' . $reason );

		return $record;
	}

	/**
	 * Sends the order to SquidCore and asks for its invoice.
	 *
	 * A block by products SquidCore does not know yet (or sees without the NCM WooCommerce has) is tried once more
	 * right after sending those products. An automatic emission blocked by missing NCM is tried once more letting the
	 * AI of SquidCore fill them, when the store allows it.
	 *
	 * @param string $trigger TRIGGER_AUTOMATIC (background job, retried on temporary failures) or TRIGGER_MANUAL.
	 *
	 * @return InvoiceRecord|\WP_Error The record after the attempt (failures included), or an error when the
	 *                                 emission could not start.
	 */
	public function emit( \WC_Order $order, string $trigger ) {
		if ( ! $this->acquireLock( $order->get_id() ) ) {
			return new \WP_Error( 'invoice_locked', __( 'The invoice of this order is already being issued. Wait a moment and refresh.', 'infixs-squidcore-erp' ), [ 'status' => 409 ] );
		}

		try {
			$record = $this->repository->findFresh( $order ) ?? new InvoiceRecord();

			if ( ! $record->canEmit( time() ) ) {
				return new \WP_Error( 'invoice_in_progress', __( 'This order already has an invoice being issued or authorized.', 'infixs-squidcore-erp' ), [ 'status' => 409 ] );
			}

			$manual = $trigger === self::TRIGGER_MANUAL;
			$api = $manual ? $this->api->interactive() : $this->api;

			if ( $manual ) {
				$this->queue->unschedule( InvoiceJobs::EMIT_HOOK, [ $order->get_id() ] );
				$record->resetEmitAttempts();
			}

			$this->attempt( $order, $record, $trigger, $api );

			$stale_products = $this->productsToPushBeforeRetry( $record );

			if ( $stale_products ) {
				$this->productSync->push( $stale_products );
				$this->attempt( $order, $record, $trigger, $api, false, true );
			}

			if ( $this->willRetryWithAi( $record, $trigger ) ) {
				$this->attempt( $order, $record, $trigger, $api, true );
			}

			return $record;
		} finally {
			$this->releaseLock( $order->get_id() );
		}
	}

	/**
	 * Puts an order back among the ones the plan limit refused after a new attempt SquidCore did not answer about
	 * the order (it was unavailable, or refused the whole account), so the next "Issue the pending invoices" takes it
	 * again. The record gets back the reason and the message of the plan limit; the order note of the attempt stays.
	 * Nothing changes when the invoice moved on meanwhile.
	 *
	 * @param InvoiceRecord $refused The record as the plan limit left it, before the attempt.
	 */
	public function restorePlanLimitFailure( \WC_Order $order, InvoiceRecord $refused ): void {
		if ( ! $refused->isPlanLimitFailure() || ! $this->acquireLock( $order->get_id() ) ) {
			return;
		}

		try {
			$record = $this->repository->findFresh( $order );

			if ( ! $record || ! $record->is( InvoiceRecord::FAILED ) || $record->isPlanLimitFailure() ) {
				return;
			}

			$record->setPlanLimitFailure( $refused->message(), $refused->planLimit() );
			$this->repository->save( $order, $record );
		} finally {
			$this->releaseLock( $order->get_id() );
		}
	}

	/**
	 * Reads the invoice from SquidCore again, restarting the status checks when it is still processing.
	 *
	 * @return InvoiceRecord|\WP_Error
	 */
	public function refresh( \WC_Order $order ) {
		if ( ! $this->acquireLock( $order->get_id() ) ) {
			return new \WP_Error( 'invoice_locked', __( 'The invoice of this order is being updated. Wait a moment and refresh.', 'infixs-squidcore-erp' ), [ 'status' => 409 ] );
		}

		try {
			$record = $this->repository->findFresh( $order );

			if ( ! $record ) {
				return new \WP_Error( 'invoice_not_found', __( 'This order has no invoice yet.', 'infixs-squidcore-erp' ), [ 'status' => 404 ] );
			}

			$response = $this->api->interactive()->show( $order->get_id() );

			if ( is_wp_error( $response ) ) {
				return ApiErrorMessage::invoiceError( $response, $this->connection->invoicePermission() );
			}

			if ( ! isset( $response['invoice'] ) || ! is_array( $response['invoice'] ) ) {
				return $record;
			}

			$was_processing = $record->is( InvoiceRecord::PROCESSING );

			$record->setPollAttempt( 0 )->setStalled( false );
			$this->applyInvoice( $order, $record, $response['invoice'] );

			if ( $was_processing && $record->is( InvoiceRecord::PROCESSING ) ) {
				$this->schedulePoll( $order, 0 );
			}

			return $record;
		} finally {
			$this->releaseLock( $order->get_id() );
		}
	}

	/**
	 * Background status check of an invoice SEFAZ is processing; queues the next check with a growing wait. Checks
	 * again later when another request is updating the invoice.
	 */
	public function poll( \WC_Order $order ): void {
		if ( ! $this->acquireLock( $order->get_id() ) ) {
			$record = $this->repository->find( $order );

			if ( $record && $record->is( InvoiceRecord::PROCESSING ) ) {
				$this->schedulePoll( $order, $record->pollAttempt() );
			}

			return;
		}

		try {
			$this->pollLocked( $order );
		} finally {
			$this->releaseLock( $order->get_id() );
		}
	}

	private function pollLocked( \WC_Order $order ): void {
		$record = $this->repository->findFresh( $order );

		if ( ! $record || ! $record->is( InvoiceRecord::PROCESSING ) ) {
			return;
		}

		$response = $this->api->show( $order->get_id() );

		if ( ! is_wp_error( $response ) && isset( $response['invoice'] ) && is_array( $response['invoice'] ) ) {
			$this->applyInvoice( $order, $record, $response['invoice'] );
		}

		if ( ! $record->is( InvoiceRecord::PROCESSING ) ) {
			return;
		}

		$attempt = $record->pollAttempt() + 1;

		if ( $attempt >= count( $this->pollDelays() ) ) {
			$this->queue->unschedule( InvoiceJobs::POLL_HOOK, [ $order->get_id() ] );
			$record->setStalled( true );
			$this->repository->save( $order, $record );
			$order->add_order_note( __( 'SquidCore: the invoice is still being processed by SEFAZ. Use "Refresh" on the order to check it again.', 'infixs-squidcore-erp' ) );
			return;
		}

		$record->setPollAttempt( $attempt );
		$this->repository->save( $order, $record );
		$this->schedulePoll( $order, $attempt );
	}

	/**
	 * Cancels the authorized invoice (SEFAZ accepts it up to 24 hours after the authorization).
	 *
	 * @param string $justification Reason sent to SEFAZ, 15 to 255 characters.
	 *
	 * @return InvoiceRecord|\WP_Error
	 */
	public function cancel( \WC_Order $order, string $justification ) {
		$justification = trim( $justification );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $justification ) : strlen( $justification );

		if ( $length < self::JUSTIFICATION_MIN_LENGTH || $length > self::JUSTIFICATION_MAX_LENGTH ) {
			return new \WP_Error(
				'invalid_justification',
				/* translators: 1: minimum length, 2: maximum length. */
				sprintf( __( 'The cancellation reason must have between %1$d and %2$d characters.', 'infixs-squidcore-erp' ), self::JUSTIFICATION_MIN_LENGTH, self::JUSTIFICATION_MAX_LENGTH ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $this->acquireLock( $order->get_id() ) ) {
			return new \WP_Error( 'invoice_locked', __( 'The invoice of this order is being updated. Wait a moment and refresh.', 'infixs-squidcore-erp' ), [ 'status' => 409 ] );
		}

		try {
			$record = $this->repository->findFresh( $order );

			if ( ! $record || ! $record->is( InvoiceRecord::AUTHORIZED ) ) {
				return new \WP_Error( 'invoice_not_authorized', __( 'Only an authorized invoice can be cancelled.', 'infixs-squidcore-erp' ), [ 'status' => 409 ] );
			}

			if ( ! $record->canCancel( time() ) ) {
				return new \WP_Error(
					'cancel_window_expired',
					__( 'The 24-hour cancellation window has ended. Issue a return invoice (NF-e de devolução) in SquidCore.', 'infixs-squidcore-erp' ),
					[ 'status' => 422 ]
				);
			}

			$response = $this->api->interactive()->cancel( $order->get_id(), $justification, $record->id() );

			if ( is_wp_error( $response ) ) {
				return ApiErrorMessage::invoiceError( $response, $this->connection->invoicePermission() );
			}

			if ( isset( $response['invoice'] ) && is_array( $response['invoice'] ) ) {
				/* translators: %s: cancellation reason. */
				$this->applyInvoice( $order, $record, $response['invoice'], sprintf( __( 'Reason: %s', 'infixs-squidcore-erp' ), $justification ) );
			}

			// Listeners of the cancellation may flag the record (a Correios pre-posting made with this invoice).
			return $this->repository->find( $order ) ?? $record;
		} finally {
			$this->releaseLock( $order->get_id() );
		}
	}

	/**
	 * Asks the AI of SquidCore to fill the missing NCM of the order products, then goes on with the blocked invoice:
	 * when every product got its NCM the emission is tried again right away (as "Try again"); when only some did,
	 * the record keeps the pending items still open.
	 *
	 * @param int[] $productIds WooCommerce product ids; empty means every line without NCM.
	 *
	 * @return array|\WP_Error {filled: array, failed: array, retried: bool}
	 */
	public function fillNcm( \WC_Order $order, array $productIds = [] ) {
		$response = $this->api->interactive()->fillNcm( $order->get_id(), $productIds );

		if ( is_wp_error( $response ) ) {
			return ApiErrorMessage::invoiceError( $response, $this->connection->invoicePermission() );
		}

		$filled = isset( $response['filled'] ) && is_array( $response['filled'] ) ? array_values( array_filter( $response['filled'], 'is_array' ) ) : [];
		$failed = isset( $response['failed'] ) && is_array( $response['failed'] ) ? array_values( $response['failed'] ) : [];

		$this->noteNcmFilled( $order, $filled );

		$retried = false;

		if ( $filled && ! $failed ) {
			$retried = $this->retryAfterNcmFill( $order, $filled );
		} elseif ( $filled ) {
			$this->dropFilledReasons( $order, $filled );
		}

		return [
			'filled' => $filled,
			'failed' => $failed,
			'retried' => $retried,
		];
	}

	/**
	 * Whether the order will get an invoice automatically, issued or still to come. Answers for orders whose
	 * emission is not queued yet (other plugins may ask before the payment hooks run).
	 */
	public function expectsInvoice( \WC_Order $order ): bool {
		if ( ! $this->settings->isEnabled() ) {
			return false;
		}

		$record = $this->repository->find( $order );

		if ( $record && $record->is( InvoiceRecord::AUTHORIZED, InvoiceRecord::CANCELLED, InvoiceRecord::SKIPPED ) ) {
			return false;
		}

		return $this->eligibility->automaticSkipReason( $order, 'correios' ) === null;
	}

	/**
	 * Whether the customer may see and download the invoice of the order (My Account, emails). By default only
	 * authorized production invoices; homologation ones too on local and development sites.
	 */
	public function isVisibleToCustomer( \WC_Order $order ): bool {
		$record = $this->repository->find( $order );

		if ( ! $record || ! $record->is( InvoiceRecord::AUTHORIZED ) || $record->key() === '' ) {
			return false;
		}

		$visible = $this->isUsable( $record );

		/**
		 * Filters whether the customer may see and download the authorized invoice of an order.
		 *
		 * @since 1.0.0
		 *
		 * @param bool      $visible Whether the invoice is shown to the customer.
		 * @param \WC_Order $order   Order.
		 * @param array     $invoice Invoice record (number, series, key, environment...).
		 */
		return (bool) apply_filters( 'infixs_squidcore_invoice_visible_to_customer', $visible, $order, $record->toArray() );
	}

	/**
	 * Whether the invoice may stand for the order outside SquidCore (customer, carriers): production invoices, and
	 * test (homologation) ones only on local and development sites, since they have no fiscal value.
	 */
	public function isUsable( InvoiceRecord $record ): bool {
		return $record->environment() === 'production' || in_array( wp_get_environment_type(), [ 'local', 'development' ], true );
	}

	/**
	 * Flags an authorized invoice for the store owner (order cancelled or refunded).
	 */
	public function flagAttention( \WC_Order $order, InvoiceRecord $record, string $reason ): void {
		$record->setAttention( $reason )->setPendingAttention( '' );
		$this->repository->save( $order, $record );

		$order->add_order_note( 'SquidCore: ' . InvoiceLabels::attention( $reason ) );

		/**
		 * Fires when an authorized invoice needs the store owner (e.g. the order was cancelled or refunded).
		 *
		 * @since 1.0.0
		 *
		 * @param int    $order_id Order id.
		 * @param string $reason   order_cancelled, order_refunded, partial_refund or prepost_uses_cancelled_invoice.
		 */
		do_action( 'infixs_squidcore_invoice_attention', $order->get_id(), $reason );
	}

	/**
	 * Drops the attention of the invoice once the store owner took care of it.
	 */
	public function clearAttention( \WC_Order $order, InvoiceRecord $record ): void {
		$record->setAttention( '' );
		$this->repository->save( $order, $record );
	}

	/**
	 * Remembers an attention to raise once the invoice being issued is authorized.
	 */
	public function deferAttention( \WC_Order $order, InvoiceRecord $record, string $reason ): void {
		$record->setPendingAttention( $reason );
		$this->repository->save( $order, $record );
	}

	/**
	 * @return int[]
	 */
	public function pollDelays(): array {
		/**
		 * Filters the waits between the status checks of an invoice SEFAZ is processing.
		 *
		 * @since 1.0.0
		 *
		 * @param int[] $delays Seconds before each check; after the last one the invoice is marked as stalled.
		 */
		$delays = array_values( array_filter( array_map( 'intval', (array) apply_filters( 'infixs_squidcore_invoice_poll_delays', self::POLL_DELAYS ) ), function ( $delay ) {
			return $delay > 0;
		} ) );

		return $delays ? $delays : self::POLL_DELAYS;
	}

	/**
	 * One emission request: counts the attempt, marks the record as being issued and sends it.
	 *
	 * @param InvoiceApi $api               Background or interactive API.
	 * @param bool       $autoFillNcmWithAi Lets the AI of SquidCore fill the missing NCM first.
	 * @param bool       $productsPushed    Comes right after sending again the products of the blocking reasons.
	 */
	private function attempt( \WC_Order $order, InvoiceRecord $record, string $trigger, InvoiceApi $api, bool $autoFillNcmWithAi = false, bool $productsPushed = false ): void {
		$record->countEmitAttempt()->setAiRetried( $autoFillNcmWithAi )->setProductsPushed( $productsPushed )->setState( InvoiceRecord::EMITTING );
		$this->repository->save( $order, $record );

		$this->send( $order, $record, $trigger, $autoFillNcmWithAi, $api );
	}

	/**
	 * Products to send again before one more attempt, when the block may come only from a product push that has not
	 * run yet. Empty once that attempt was made.
	 *
	 * @return int[] WooCommerce product ids.
	 */
	private function productsToPushBeforeRetry( InvoiceRecord $record ): array {
		if ( ! $record->is( InvoiceRecord::BLOCKED ) || $record->productsPushed() || $record->aiRetried() ) {
			return [];
		}

		return $this->productSync->staleProducts( $record->reasons() );
	}

	/**
	 * Tries the blocked emission again after the AI filled every missing NCM. When it cannot start (another request
	 * holds the invoice), the pending items of the filled products are dropped so the box does not offer them again.
	 *
	 * @param array[] $filled Products filled by the AI ({wcProductId, ncm...}).
	 *
	 * @return bool Whether the emission was tried again.
	 */
	private function retryAfterNcmFill( \WC_Order $order, array $filled ): bool {
		$record = $this->repository->findFresh( $order );

		if ( ! $record || ! $record->is( InvoiceRecord::BLOCKED ) ) {
			return false;
		}

		if ( ! is_wp_error( $this->emit( $order, self::TRIGGER_MANUAL ) ) ) {
			return true;
		}

		$this->dropFilledReasons( $order, $filled );

		return false;
	}

	/**
	 * Removes the missing-NCM items of the products the AI filled, keeping every other pending item.
	 *
	 * @param array[] $filled Products filled by the AI ({wcProductId, ncm...}).
	 */
	private function dropFilledReasons( \WC_Order $order, array $filled ): void {
		$record = $this->repository->findFresh( $order );

		if ( ! $record || ! $record->is( InvoiceRecord::BLOCKED ) ) {
			return;
		}

		$filled_ids = array_filter( array_map( function ( $item ) {
			return (int) ( $item['wcProductId'] ?? 0 );
		}, $filled ) );

		$remaining = array_values( array_filter( $record->reasons(), function ( $reason ) use ( $filled_ids ) {
			return ( $reason['code'] ?? '' ) !== InvoiceProductSync::MISSING_NCM
				|| ! in_array( (int) ( $reason['wcProductId'] ?? 0 ), $filled_ids, true );
		} ) );

		$record->setBlocked( $record->message(), $remaining, $record->aiNcmAvailable() );
		$this->repository->save( $order, $record );
	}

	/**
	 * Private order note with the products that got an NCM from the AI of SquidCore.
	 *
	 * @param mixed $filled Products filled by the AI ({wcProductId, ncm, descricao}).
	 */
	private function noteNcmFilled( \WC_Order $order, $filled ): void {
		$note = InvoiceLabels::ncmFilledNote( is_array( $filled ) ? $filled : [] );

		if ( $note !== '' ) {
			$order->add_order_note( $note );
		}
	}

	/**
	 * One more automatic attempt letting the AI of SquidCore fill the missing NCM, when the store allows it and
	 * SquidCore can.
	 */
	private function willRetryWithAi( InvoiceRecord $record, string $trigger ): bool {
		return $trigger === self::TRIGGER_AUTOMATIC
			&& $record->is( InvoiceRecord::BLOCKED )
			&& ! $record->aiRetried()
			&& $record->hasAiFillableReasons()
			&& $this->settings->autoFillNcmWithAi();
	}

	/**
	 * @param InvoiceApi $api Background or interactive API.
	 *
	 * @return InvoiceRecord
	 */
	private function send( \WC_Order $order, InvoiceRecord $record, string $trigger, bool $autoFillNcmWithAi, InvoiceApi $api ): InvoiceRecord {
		if ( ! $this->connection->isConnected() ) {
			return $this->fail( $order, $record, __( 'SquidCore is not connected. Connect the store in the plugin settings and try again.', 'infixs-squidcore-erp' ), false );
		}

		$pushed = $this->dispatcher->pushNow( 'order.updated', $order->get_id() );

		if ( is_wp_error( $pushed ) && $pushed->get_error_code() === 'squidcore_sync_ignored' ) {
			$message = __( 'SquidCore is not accepting orders from this store. Enable receiving orders in the WooCommerce integration in SquidCore and try again.', 'infixs-squidcore-erp' );

			return $this->block( $order, $record, $message, [
				[
					'code' => 'ORDER_SYNC_IGNORED',
					'target' => 'company',
					'message' => $message,
				],
			], false, $trigger );
		}

		if ( is_wp_error( $pushed ) ) {
			$this->handleError( $order, $record, $pushed, $trigger );

			// The emission carries the order push: a new attempt pushes again, so the push is lost for good only when
			// no attempt follows. Counted here, not by pushNow(), which does not know the retry policy of its caller.
			if ( $record->is( InvoiceRecord::FAILED ) ) {
				$this->activity->increment( ActivityRepository::FAILED );
			}

			return $record;
		}

		$response = $api->emit( $order->get_id(), $autoFillNcmWithAi ? [ 'autoFillNcmWithAi' => true ] : [] );

		// The NCM the AI filled stays in SquidCore even when the emission does not go through.
		if ( $autoFillNcmWithAi ) {
			$this->noteNcmFilled( $order, is_wp_error( $response ) ? ( ApiErrorMessage::body( $response )['ncmFilled'] ?? [] ) : ( $response['ncmFilled'] ?? [] ) );
		}

		if ( is_wp_error( $response ) ) {
			return $this->handleError( $order, $record, $response, $trigger );
		}

		if ( ! isset( $response['invoice'] ) || ! is_array( $response['invoice'] ) ) {
			return $this->fail( $order, $record, __( 'SquidCore did not return the invoice. Refresh to check it.', 'infixs-squidcore-erp' ), false );
		}

		$this->applyInvoice( $order, $record, $response['invoice'] );

		return $record;
	}

	private function handleError( \WC_Order $order, InvoiceRecord $record, \WP_Error $error, string $trigger ): InvoiceRecord {
		$code = ApiErrorMessage::code( $error );
		$body = ApiErrorMessage::body( $error );
		$message = ApiErrorMessage::describeInvoice( $error, $this->connection->invoicePermission() );

		if ( $code === 'NFE_BLOCKED' ) {
			$reasons = isset( $body['blockingReasons'] ) && is_array( $body['blockingReasons'] ) ? $body['blockingReasons'] : [];

			return $this->block( $order, $record, $message, $reasons, ! empty( $body['aiNcmAvailable'] ), $trigger );
		}

		if ( $code === 'NFE_EMISSION_REJECTED' && isset( $body['invoice'] ) && is_array( $body['invoice'] ) ) {
			$this->applyInvoice( $order, $record, $body['invoice'] );

			return $record;
		}

		if ( $code === 'PLAN_LIMIT_REACHED' ) {
			return $this->failForPlanLimit( $order, $record, $message, $body );
		}

		// Any other 402 refuses every invoice of the account (subscription, plan without invoices), not this one.
		if ( ApiErrorMessage::status( $error ) === 402 ) {
			$record->setAccountFailure( $message );

			return $this->saveFailure( $order, $record, $this->failureNote( $message ) );
		}

		$transient = ApiErrorMessage::isTransient( $error );

		if ( $transient && $trigger === self::TRIGGER_AUTOMATIC && $record->emitAttempts() <= count( self::RETRY_DELAYS ) ) {
			$timestamp = time() + self::RETRY_DELAYS[ $record->emitAttempts() - 1 ];

			$record->setScheduled( $timestamp );
			$this->repository->save( $order, $record );

			// Queued from inside the running emission job, which still counts as a job with the same arguments.
			$this->queue->schedule( InvoiceJobs::EMIT_HOOK, [ $order->get_id() ], $timestamp );

			$order->add_order_note( sprintf(
				/* translators: 1: error message, 2: date and time of the next attempt. */
				__( 'SquidCore: the invoice could not be issued now (%1$s). New attempt on %2$s.', 'infixs-squidcore-erp' ),
				$message,
				InvoiceLabels::dateTime( $timestamp )
			) );

			return $record;
		}

		return $this->fail( $order, $record, $message, $transient );
	}

	/**
	 * The failure action waits when the AI of SquidCore is about to try again, so listeners only hear of a block
	 * that stays.
	 *
	 * @param array[] $reasons Blocking reasons (NfeBlockingReason).
	 */
	private function block( \WC_Order $order, InvoiceRecord $record, string $message, array $reasons, bool $aiNcmAvailable, string $trigger ): InvoiceRecord {
		$record->setBlocked( $message, $reasons, $aiNcmAvailable );
		$this->repository->save( $order, $record );

		// One more attempt follows right after sending the products: only its outcome is told.
		if ( $this->productsToPushBeforeRetry( $record ) ) {
			return $record;
		}

		$details = array_filter( array_map( function ( $reason ) {
			return $reason['message'];
		}, $record->reasons() ) );

		$order->add_order_note( 'SquidCore: ' . $message . ( $details ? "\n- " . implode( "\n- ", $details ) : '' ) );

		if ( ! $this->willRetryWithAi( $record, $trigger ) ) {
			$this->fireFailed( $order, $record );
		}

		return $record;
	}

	private function fail( \WC_Order $order, InvoiceRecord $record, string $message, bool $transient ): InvoiceRecord {
		$record->setFailed( $message, $transient );

		return $this->saveFailure( $order, $record, $this->failureNote( $message ) );
	}

	/**
	 * The invoice limit of the plan was reached: the record keeps the reason, so the store sees every order it left
	 * without an invoice at once, and the note links to the plans.
	 *
	 * @param array $denial Body of the 402: metric, limit, used.
	 */
	private function failForPlanLimit( \WC_Order $order, InvoiceRecord $record, string $message, array $denial ): InvoiceRecord {
		$record->setPlanLimitFailure( $message, $denial );
		$this->saveFailure( $order, $record, $this->failureNote( $message ) . ' ' . PlanLimitLabels::plansLink( $this->urls->plansUrl() ) );

		/**
		 * Fires when SquidCore refuses the invoice of an order because the invoice limit of the plan was reached.
		 *
		 * @since 1.5.0
		 *
		 * @param int   $order_id Order id.
		 * @param array $limit    What SquidCore told: metric, limit (null: no limit) and used.
		 */
		do_action( 'infixs_squidcore_invoice_plan_limit_reached', $order->get_id(), $record->planLimit() );

		return $record;
	}

	private function failureNote( string $message ): string {
		/* translators: %s: error message. */
		return sprintf( __( 'SquidCore: the invoice was not issued. %s', 'infixs-squidcore-erp' ), $message );
	}

	private function saveFailure( \WC_Order $order, InvoiceRecord $record, string $note ): InvoiceRecord {
		$this->repository->save( $order, $record );
		$order->add_order_note( $note );
		$this->fireFailed( $order, $record );

		return $record;
	}

	/**
	 * Applies an invoice returned by SquidCore: saves it, notes the change of state and fires its action.
	 *
	 * @param array $invoice InvoiceView.
	 * @param string $extraNote Appended to the note of the new state.
	 */
	private function applyInvoice( \WC_Order $order, InvoiceRecord $record, array $invoice, string $extraNote = '' ): void {
		$previous_state = $record->state();
		$previous_id = $record->id();

		$record->applyRemote( $invoice );

		$changed = $record->state() !== $previous_state || $record->id() !== $previous_id;
		$attention = '';

		if ( ! $record->is( InvoiceRecord::PROCESSING ) ) {
			$this->queue->unschedule( InvoiceJobs::POLL_HOOK, [ $order->get_id() ] );
		}

		if ( $record->is( InvoiceRecord::AUTHORIZED ) && $changed ) {
			$attention = $record->pendingAttention();
		}

		if ( $record->is( InvoiceRecord::CANCELLED ) ) {
			$record->setAttention( '' )->setPendingAttention( '' );
		}

		$this->repository->save( $order, $record );

		if ( $record->is( InvoiceRecord::PROCESSING ) && $previous_state !== InvoiceRecord::PROCESSING ) {
			$record->setPollAttempt( 0 );
			$this->repository->save( $order, $record );
			$this->schedulePoll( $order, 0 );
		}

		if ( ! $changed ) {
			return;
		}

		$note = $this->stateNote( $record );

		if ( $note !== '' ) {
			$order->add_order_note( 'SquidCore: ' . $note . ( $extraNote !== '' ? ' ' . $extraNote : '' ) );
		}

		switch ( $record->state() ) {
			case InvoiceRecord::AUTHORIZED:
				/**
				 * Fires once when the invoice of an order is authorized by SEFAZ.
				 *
				 * @since 1.0.0
				 *
				 * @param int       $order_id Order id.
				 * @param array     $invoice  Invoice record (number, series, key, environment, authorizedAt...).
				 * @param \WC_Order $order    Order, the same object the invoice was saved on.
				 */
				do_action( 'infixs_squidcore_invoice_authorized', $order->get_id(), $record->toArray(), $order );

				if ( $attention !== '' ) {
					$this->flagAttention( $order, $record, $attention );
				}
				break;

			case InvoiceRecord::ERROR:
			case InvoiceRecord::DENIED:
				$this->fireFailed( $order, $record );
				break;

			case InvoiceRecord::CANCELLED:
				/**
				 * Fires when the invoice of an order is cancelled.
				 *
				 * @since 1.0.0
				 *
				 * @param int       $order_id Order id.
				 * @param array     $invoice  Invoice record (number, series, key...).
				 * @param \WC_Order $order    Order, the same object the invoice was saved on.
				 */
				do_action( 'infixs_squidcore_invoice_cancelled', $order->get_id(), $record->toArray(), $order );
				break;
		}
	}

	private function stateNote( InvoiceRecord $record ): string {
		switch ( $record->state() ) {
			case InvoiceRecord::PROCESSING:
				return __( 'invoice (NF-e) sent, waiting for SEFAZ.', 'infixs-squidcore-erp' );
			case InvoiceRecord::AUTHORIZED:
				/* translators: 1: invoice number, 2: series, 3: access key. */
				return sprintf( __( 'invoice (NF-e) number %1$s, series %2$s authorized. Access key: %3$s.', 'infixs-squidcore-erp' ), $record->number(), $record->series(), $record->key() );
			case InvoiceRecord::ERROR:
				/* translators: %s: message returned by SEFAZ. */
				return sprintf( __( 'the invoice (NF-e) was rejected. %s', 'infixs-squidcore-erp' ), $record->message() ) . $this->rejectionNoteLine( $record );
			case InvoiceRecord::DENIED:
				/* translators: %s: message returned by SEFAZ. */
				return sprintf( __( 'the invoice (NF-e) was denied by SEFAZ. %s', 'infixs-squidcore-erp' ), $record->message() ) . $this->rejectionNoteLine( $record );
			case InvoiceRecord::CANCELLED:
				/* translators: %s: invoice number. */
				return sprintf( __( 'invoice (NF-e) number %s cancelled.', 'infixs-squidcore-erp' ), $record->number() );
		}

		return '';
	}

	/**
	 * What to do about a rejection the store owner can fix (a number another system used...), on a line of its own.
	 */
	private function rejectionNoteLine( InvoiceRecord $record ): string {
		$rejection = $record->rejection();

		return $rejection ? "\n" . SefazRejectionAdvice::noteLine( $rejection ) : '';
	}

	/**
	 * Tells the listeners and the activity chart of a failure that stays: an interim block (products about to be
	 * pushed, AI about to fill the NCM) never gets here, so "needs you" counts what the store owner really has to see.
	 */
	private function fireFailed( \WC_Order $order, InvoiceRecord $record ): void {
		$this->activity->increment( ActivityRepository::INVOICES_NEEDING_ACTION );

		/**
		 * Fires when the invoice of an order could not be issued: blocked by pending items, rejected or denied
		 * by SEFAZ, or the request failed.
		 *
		 * @since 1.0.0
		 *
		 * @param int     $order_id Order id.
		 * @param string  $state    blocked, error, denied or failed.
		 * @param string  $message  Message for the store owner.
		 * @param array[] $reasons  Blocking reasons, when blocked.
		 */
		do_action( 'infixs_squidcore_invoice_failed', $order->get_id(), $record->state(), $record->message(), $record->reasons() );
	}

	/**
	 * @param int $attempt Index in pollDelays() of the wait before the check.
	 */
	private function schedulePoll( \WC_Order $order, int $attempt ): void {
		$delays = $this->pollDelays();
		$delay = $delays[ min( $attempt, count( $delays ) - 1 ) ];

		$this->queue->unschedule( InvoiceJobs::POLL_HOOK, [ $order->get_id() ] );
		$this->queue->schedule( InvoiceJobs::POLL_HOOK, [ $order->get_id() ], time() + $delay );
	}

	/**
	 * Per-order lock shared by every request that changes the invoice.
	 */
	private function acquireLock( int $orderId ): bool {
		return $this->lock->acquire( $this->lockName( $orderId ), self::LOCK_TTL );
	}

	private function releaseLock( int $orderId ): void {
		$this->lock->release( $this->lockName( $orderId ) );
	}

	private function lockName( int $orderId ): string {
		return 'infixs_squidcore_invoice_lock_' . $orderId;
	}
}
