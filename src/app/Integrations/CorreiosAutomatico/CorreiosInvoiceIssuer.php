<?php

namespace Infixs\SquidCore\Integrations\CorreiosAutomatico;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin\InvoiceMetaBox;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceEligibility;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceLabels;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRecord;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Services\ConnectionService;
use Infixs\SquidCore\Support\ApiErrorMessage;

defined( 'ABSPATH' ) || exit;

/**
 * Makes SquidCore the invoice (NF-e) issuer of Correios Automático: its pre-posting screen and its order list offer to
 * issue the NF-e of an order that has none, and follow it until SEFAZ answers.
 *
 * Telling whether SquidCore can issue reads only what the store keeps (the invoice record), since Correios Automático
 * asks it for every order of its panel; issuing and checking go the same way as the buttons of the order screen. Like
 * the bridge, it stays out of the shipments of marketplace vendors and of the orders that hold another NF-e. Unlike the
 * invoice box of the order screen, its panel offers the emission on the order list, away from the order: the invoice
 * of an order whose sale ended, or that a marketplace split, is not issued from there.
 *
 * @package Infixs\SquidCore
 * @since   1.6.0
 */
class CorreiosInvoiceIssuer {

	public const STATE_NONE = 'none';

	/**
	 * The automatic emission is queued; issuing now takes its place.
	 */
	public const STATE_SCHEDULED = 'scheduled';

	public const STATE_PROCESSING = 'processing';

	public const STATE_BLOCKED = 'blocked';

	public const STATE_FAILED = 'failed';

	public const STATE_AUTHORIZED = 'authorized';

	public const STATE_TEST = 'test';

	/**
	 * The emission or the check could not reach SquidCore (not connected, without the NF-e permissions, service down),
	 * has to wait for the automatic emission or is not made from Correios Automático for this order (its sale ended, a
	 * marketplace split it): the same request may go through later.
	 */
	public const STATE_UNAVAILABLE = 'unavailable';

	/**
	 * Most seconds Correios Automático waits before checking again an invoice SEFAZ is processing, while the store
	 * owner watches it on the pre-posting screen.
	 */
	public const RETRY_AFTER = 5;

	/**
	 * State told to Correios Automático for each invoice state; any other (none yet, skipped, cancelled) lets a new
	 * invoice be issued.
	 */
	private const STATES = [
		InvoiceRecord::SCHEDULED => self::STATE_SCHEDULED,
		InvoiceRecord::EMITTING => self::STATE_PROCESSING,
		InvoiceRecord::PROCESSING => self::STATE_PROCESSING,
		InvoiceRecord::BLOCKED => self::STATE_BLOCKED,
		InvoiceRecord::ERROR => self::STATE_FAILED,
		InvoiceRecord::DENIED => self::STATE_FAILED,
		InvoiceRecord::FAILED => self::STATE_FAILED,
		InvoiceRecord::AUTHORIZED => self::STATE_AUTHORIZED,
	];

	/**
	 * Length of an NF-e access key.
	 */
	private const KEY_LENGTH = 44;

	/**
	 * @var InvoiceService
	 */
	protected $service;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	/**
	 * @var InvoiceEligibility
	 */
	protected $eligibility;

	/**
	 * What connectionProblem() said the first time issuer() asked in this request, null before. Correios Automático
	 * asks for every order of its list, and the connection is read straight from the table.
	 *
	 * @var string|null
	 */
	private $listedConnectionProblem = null;

	/**
	 * @param InvoiceEligibility $eligibility Whether the sale of an order ended, and whether a marketplace split it.
	 */
	public function __construct( InvoiceService $service, ConnectionService $connection, InvoiceEligibility $eligibility ) {
		$this->service = $service;
		$this->connection = $connection;
		$this->eligibility = $eligibility;
	}

	public function register(): void {
		add_filter( 'infixs_correios_automatico_invoice_issuer', [ $this, 'issuer' ], 10, 3 );
		add_filter( 'infixs_correios_automatico_issue_invoice', [ $this, 'issue' ], 10, 3 );
		add_filter( 'infixs_correios_automatico_refresh_invoice_issue', [ $this, 'refresh' ], 10, 3 );
		add_action( 'infixs_squidcore_connection_changed', [ $this, 'forgetConnection' ] );
	}

	/**
	 * Reads the connection again on the next issuer() of this request.
	 */
	public function forgetConnection(): void {
		$this->listedConnectionProblem = null;
	}

	/**
	 * SquidCore as the issuer of the NF-e of an order: whether it can issue it now and the state of the invoice it
	 * already has. Never calls the API.
	 *
	 * @param array|null $issuer  Answer of the previous issuers.
	 * @param \WC_Order  $order
	 * @param array      $context vendor_id, invoice (the NF-e Correios Automático resolved for the order).
	 *
	 * @return array|null {source, label, available, reason, state, message, scheduled_at, reasons, resolve_url}
	 */
	public function issuer( $issuer, $order, $context = [] ) {
		if ( $issuer !== null || ! $order instanceof \WC_Order ) {
			return $issuer;
		}

		$record = $this->service->find( $order );

		if ( ! $this->issuesFor( $context, $record ) ) {
			return $issuer;
		}

		$state = $this->stateOf( $record );
		$reason = $this->unavailableReason( $order, $state );

		return [
			'source' => CorreiosInvoiceBridge::SOURCE,
			'label' => CorreiosInvoiceBridge::SOURCE_LABEL,
			'available' => $reason === '',
			'reason' => $reason,
			'state' => $state,
			'message' => $record ? $this->message( $record, $state ) : '',
			'scheduled_at' => $record && $state === self::STATE_SCHEDULED && $record->scheduledAt() > 0 ? $record->scheduledAt() : null,
			'reasons' => $record && $state === self::STATE_BLOCKED ? $this->reasons( $record ) : [],
			'resolve_url' => $this->resolveUrl( $order ),
		];
	}

	/**
	 * Issues the NF-e of the order now, as "Issue now" on the order screen does: a queued automatic emission is
	 * dropped and the invoice goes out at once.
	 *
	 * @param array|null $result  Answer of the previous issuers.
	 * @param \WC_Order  $order
	 * @param array      $context vendor_id, invoice.
	 *
	 * @return array|null {state, message, reasons, invoice, retry_after, resolve_url}
	 */
	public function issue( $result, $order, $context = [] ) {
		if ( $result !== null || ! $order instanceof \WC_Order ) {
			return $result;
		}

		if ( ! $this->issuesFor( $context, $this->service->find( $order ) ) ) {
			return $result;
		}

		// SquidCore may refuse the token while issuing: the next issuer() of this request reads the connection again.
		$this->forgetConnection();
		$problem = $this->connectionProblem();

		if ( $problem === '' ) {
			$problem = $this->orderProblem( $order );
		}

		if ( $problem !== '' ) {
			return $this->result( $order, self::STATE_UNAVAILABLE, $problem );
		}

		$record = $this->service->emit( $order, InvoiceService::TRIGGER_MANUAL );

		return is_wp_error( $record ) ? $this->fromError( $order, $record ) : $this->fromRecord( $order, $record );
	}

	/**
	 * Checks the invoice of the order in SquidCore again, as "Refresh" on the order screen does.
	 *
	 * @param array|null $result  Answer of the previous issuers.
	 * @param \WC_Order  $order
	 * @param array      $context vendor_id, invoice.
	 *
	 * @return array|null {state, message, reasons, invoice, retry_after, resolve_url}
	 */
	public function refresh( $result, $order, $context = [] ) {
		if ( $result !== null || ! $order instanceof \WC_Order ) {
			return $result;
		}

		$record = $this->service->find( $order );

		if ( ! $this->issuesFor( $context, $record ) ) {
			return $result;
		}

		// SquidCore may refuse the token while checking: the next issuer() of this request reads the connection again.
		$this->forgetConnection();
		$problem = $this->connectionProblem();

		if ( $problem !== '' ) {
			return $this->result( $order, self::STATE_UNAVAILABLE, $problem );
		}

		// Nothing in SquidCore to read (pending items found before the invoice was created, an emission running or
		// queued): the record tells it all.
		if ( $record && ! $record->canRefresh() ) {
			return $this->fromRecord( $order, $record );
		}

		$refreshed = $this->service->refresh( $order );

		if ( ! is_wp_error( $refreshed ) ) {
			return $this->fromRecord( $order, $refreshed );
		}

		// A blip of the API while SEFAZ processes the invoice: Correios Automático keeps checking.
		if ( $record && ApiErrorMessage::isTransient( $refreshed ) && $this->stateOf( $record ) === self::STATE_PROCESSING ) {
			return $this->fromRecord( $order, $record );
		}

		return $this->fromError( $order, $refreshed );
	}

	/**
	 * Whether SquidCore issues the NF-e of the order: never for the shipment of a marketplace vendor (vendors issue
	 * their own invoices) nor for an order that holds the NF-e of another provider (or one typed by hand).
	 *
	 * @param mixed $context vendor_id, invoice.
	 */
	private function issuesFor( $context, ?InvoiceRecord $record ): bool {
		$context = is_array( $context ) ? $context : [];

		return empty( $context['vendor_id'] ) && CorreiosInvoiceBridge::holdsInvoice( $record, $context['invoice'] ?? null );
	}

	private function stateOf( ?InvoiceRecord $record ): string {
		if ( ! $record || ! isset( self::STATES[ $record->state() ] ) ) {
			return self::STATE_NONE;
		}

		$state = self::STATES[ $record->state() ];

		if ( $state === self::STATE_AUTHORIZED && ! $this->service->isUsable( $record ) ) {
			return self::STATE_TEST;
		}

		// An emission interrupted long ago may start again.
		if ( $record->isEmittingStale( time() ) ) {
			return self::STATE_NONE;
		}

		return $state;
	}

	/**
	 * Why the NF-e cannot be issued now, blank when it can.
	 */
	private function unavailableReason( \WC_Order $order, string $state ): string {
		if ( $this->listedConnectionProblem === null ) {
			$this->listedConnectionProblem = $this->connectionProblem();
		}

		if ( $this->listedConnectionProblem !== '' ) {
			return $this->listedConnectionProblem;
		}

		switch ( $state ) {
			case self::STATE_PROCESSING:
			case self::STATE_AUTHORIZED:
				return __( 'This order already has an invoice being issued or authorized.', 'infixs-squidcore-erp' );
			case self::STATE_TEST:
				return $this->testMessage();
		}

		return $this->orderProblem( $order );
	}

	/**
	 * Why no request can reach SquidCore, blank when it can.
	 */
	private function connectionProblem(): string {
		if ( ! $this->connection->isConnected() ) {
			return __( 'SquidCore is not connected. Connect the store in the SquidCore settings to issue the NF-e from here.', 'infixs-squidcore-erp' );
		}

		if ( $this->connection->invoicePermission() === false ) {
			return __( 'The SquidCore connection cannot issue invoices (NF-e): the NF-e permissions are missing. Fix the connection in the SquidCore settings.', 'infixs-squidcore-erp' );
		}

		return '';
	}

	/**
	 * Why the NF-e of the order is not issued from Correios Automático, blank when it is: its sale ended, so a new
	 * invoice would have fiscal value for a sale undone, or a marketplace split it, and the automatic emission skips
	 * both the order split and its sub-orders. The invoice box of the order screen still issues it. Reads only the
	 * order.
	 */
	private function orderProblem( \WC_Order $order ): string {
		if ( $this->eligibility->isSaleEnded( $order ) ) {
			switch ( $order->get_status() ) {
				case 'cancelled':
					return __( 'This order was cancelled, so its NF-e is not issued from here. To issue it anyway, open the order.', 'infixs-squidcore-erp' );
				case 'refunded':
					return __( 'This order was refunded, so its NF-e is not issued from here. To issue it anyway, open the order.', 'infixs-squidcore-erp' );
				case 'trash':
					return __( 'This order is in the trash, so its NF-e is not issued from here. To issue it anyway, open the order.', 'infixs-squidcore-erp' );
				default:
					return __( 'The payment of this order failed, so its NF-e is not issued from here. To issue it anyway, open the order.', 'infixs-squidcore-erp' );
			}
		}

		if ( $this->eligibility->isSplitIntoSubOrders( $order ) ) {
			return __( 'This order was split into marketplace sub-orders, so its NF-e is not issued from here. To issue it anyway, open the order.', 'infixs-squidcore-erp' );
		}

		if ( $this->eligibility->isSubOrder( $order ) ) {
			return __( 'This is a marketplace sub-order, so its NF-e is not issued from here. To issue it anyway, open the order.', 'infixs-squidcore-erp' );
		}

		return '';
	}

	private function fromRecord( \WC_Order $order, InvoiceRecord $record ): array {
		$state = $this->stateOf( $record );
		$message = $this->message( $record, $state );

		switch ( $state ) {
			case self::STATE_PROCESSING:
				return $this->result( $order, $state, $message, [ 'retry_after' => $this->retryAfter() ] );
			case self::STATE_AUTHORIZED:
				return $this->result( $order, $state, $message, [ 'invoice' => $this->invoice( $record ) ] );
			case self::STATE_BLOCKED:
				return $this->result( $order, $state, $message, [ 'reasons' => $this->reasons( $record ) ] );
			case self::STATE_FAILED:
				return $this->result( $order, $record->isTransientFailure() ? self::STATE_UNAVAILABLE : $state, $message );
			case self::STATE_TEST:
				return $this->result( $order, $state, $message );
			case self::STATE_SCHEDULED:
				// The automatic emission (also the one that retries a temporary failure of SquidCore) runs by itself
				// later; issuing again sends it now.
				return $this->result( $order, self::STATE_UNAVAILABLE, $this->scheduledMessage( $record ) );
		}

		// No invoice to tell about: skipped, cancelled or an emission that died halfway.
		return $this->result( $order, self::STATE_FAILED, $message !== '' ? $message : __( 'This order has no invoice yet.', 'infixs-squidcore-erp' ) );
	}

	private function fromError( \WC_Order $order, \WP_Error $error ): array {
		switch ( $error->get_error_code() ) {
			case 'invoice_locked':
				// Another request is issuing or checking the invoice right now.
				return $this->result( $order, self::STATE_PROCESSING, $error->get_error_message(), [ 'retry_after' => $this->retryAfter() ] );
			case 'invoice_in_progress':
				$record = $this->service->find( $order );

				if ( $record ) {
					return $this->fromRecord( $order, $record );
				}
				break;
		}

		return $this->result( $order, ApiErrorMessage::isTransient( $error ) ? self::STATE_UNAVAILABLE : self::STATE_FAILED, $error->get_error_message() );
	}

	private function message( InvoiceRecord $record, string $state ): string {
		switch ( $state ) {
			case self::STATE_NONE:
				return $this->noneMessage( $record );
			case self::STATE_SCHEDULED:
				// The time goes in scheduled_at, for Correios Automático to write it its own way.
				return '';
			case self::STATE_PROCESSING:
				return $record->is( InvoiceRecord::EMITTING )
					? __( 'Sending the invoice to SquidCore.', 'infixs-squidcore-erp' )
					: __( 'Waiting for SEFAZ to authorize the invoice.', 'infixs-squidcore-erp' );
			case self::STATE_AUTHORIZED:
				/* translators: %s: invoice number. */
				return sprintf( __( 'NF-e no. %s', 'infixs-squidcore-erp' ), $record->number() );
			case self::STATE_TEST:
				return $this->testMessage();
		}

		if ( $record->message() !== '' ) {
			return $record->message();
		}

		/* translators: %s: state of the invoice, e.g. "Processing". */
		return sprintf( __( 'NF-e: %s', 'infixs-squidcore-erp' ), InvoiceLabels::state( $record->state() ) );
	}

	/**
	 * What to know before issuing the invoice of an order that has none now: why the automatic emission skipped it
	 * (another plugin already issued its NF-e, for example), that its invoice was cancelled or that the last emission
	 * was interrupted. Blank when there is nothing to tell.
	 */
	private function noneMessage( InvoiceRecord $record ): string {
		if ( $record->is( InvoiceRecord::SKIPPED ) ) {
			return $record->message();
		}

		if ( $record->is( InvoiceRecord::CANCELLED ) && $record->number() !== '' ) {
			/* translators: %s: invoice number. */
			return sprintf( __( 'NF-e no. %s of this order was cancelled: issuing creates a new invoice.', 'infixs-squidcore-erp' ), $record->number() );
		}

		if ( $record->is( InvoiceRecord::EMITTING ) ) {
			return __( 'The last emission of the invoice was interrupted before SquidCore answered. Issue it again.', 'infixs-squidcore-erp' );
		}

		return '';
	}

	private function scheduledMessage( InvoiceRecord $record ): string {
		if ( $record->scheduledAt() <= 0 ) {
			/* translators: %s: state of the invoice, e.g. "Processing". */
			return sprintf( __( 'NF-e: %s', 'infixs-squidcore-erp' ), InvoiceLabels::state( $record->state() ) );
		}

		/* translators: %s: date and time of the emission. */
		return sprintf( __( 'The automatic emission of the invoice is scheduled for %s.', 'infixs-squidcore-erp' ), InvoiceLabels::dateTime( $record->scheduledAt() ) );
	}

	private function testMessage(): string {
		return __( 'The invoice of this order was issued in the test environment (homologation): it has no fiscal value and cannot go with the pre-posting.', 'infixs-squidcore-erp' );
	}

	/**
	 * Number and key of the authorized invoice, null without the whole access key.
	 *
	 * @return array{invoice_number: string, invoice_key: string}|null
	 */
	private function invoice( InvoiceRecord $record ): ?array {
		if ( strlen( $record->key() ) !== self::KEY_LENGTH ) {
			return null;
		}

		return [
			'invoice_number' => $record->number(),
			'invoice_key' => $record->key(),
		];
	}

	/**
	 * @return array<int, array{message: string}> Pending items of the last attempt.
	 */
	private function reasons( InvoiceRecord $record ): array {
		$reasons = [];

		foreach ( $record->reasons() as $reason ) {
			$message = (string) ( $reason['message'] ?? '' );

			if ( $message !== '' ) {
				$reasons[] = [ 'message' => $message ];
			}
		}

		return $reasons;
	}

	/**
	 * The invoice box on the order screen, where the pending items are fixed and the invoice is followed.
	 */
	private function resolveUrl( \WC_Order $order ): string {
		return $order->get_edit_order_url() . '#' . InvoiceMetaBox::ID;
	}

	/**
	 * The first wait of the status checks of the plugin, up to RETRY_AFTER.
	 */
	private function retryAfter(): int {
		return min( self::RETRY_AFTER, $this->service->pollDelays()[0] );
	}

	/**
	 * Outcome of an emission or of a status check, as Correios Automático takes it.
	 *
	 * @param array $extra reasons, invoice, retry_after.
	 *
	 * @return array{state: string, message: string, reasons: array, invoice: array|null, retry_after: int|null, resolve_url: string}
	 */
	private function result( \WC_Order $order, string $state, string $message, array $extra = [] ): array {
		return array_merge( [
			'state' => $state,
			'message' => $message,
			'reasons' => [],
			'invoice' => null,
			'retry_after' => null,
			'resolve_url' => $this->resolveUrl( $order ),
		], $extra );
	}
}
