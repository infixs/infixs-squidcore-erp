<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Summary of the invoice (NF-e) of an order, as the store keeps it.
 *
 * SquidCore owns the invoice; this record holds the local state of the emission and the last answer of SquidCore,
 * so screens and emails never call the API.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceRecord {

	public const SCHEDULED = 'scheduled';
	public const EMITTING = 'emitting';
	public const PROCESSING = 'processing';
	public const AUTHORIZED = 'authorized';
	public const BLOCKED = 'blocked';
	public const ERROR = 'error';
	public const DENIED = 'denied';
	public const FAILED = 'failed';
	public const CANCELLED = 'cancelled';
	public const SKIPPED = 'skipped';

	public const ATTENTION_ORDER_CANCELLED = 'order_cancelled';
	public const ATTENTION_ORDER_REFUNDED = 'order_refunded';
	public const ATTENTION_PARTIAL_REFUND = 'partial_refund';
	public const ATTENTION_PREPOST_USES_CANCELLED_INVOICE = 'prepost_uses_cancelled_invoice';

	/**
	 * Hours after the authorization in which SEFAZ accepts the cancellation.
	 */
	public const CANCEL_WINDOW_HOURS = 24;

	/**
	 * An emission still running after this many seconds is taken as interrupted and may start again.
	 */
	public const EMITTING_STALE_AFTER = 600;

	/**
	 * Local state for each status of the invoice in SquidCore.
	 */
	private const REMOTE_STATES = [
		'DRAFT' => self::PROCESSING,
		'PROCESSING' => self::PROCESSING,
		'AUTHORIZED' => self::AUTHORIZED,
		'ERROR' => self::ERROR,
		'DENIED' => self::DENIED,
		'CANCELLED' => self::CANCELLED,
	];

	private const DEFAULTS = [
		'state' => '',
		'attention' => '',
		'pendingAttention' => '',
		'id' => '',
		'status' => '',
		'number' => '',
		'series' => '',
		'key' => '',
		'environment' => '',
		'total' => 0,
		'issuedAt' => '',
		'authorizedAt' => '',
		'cancelledAt' => '',
		'cancellableUntil' => '',
		'sefazStatus' => '',
		'sefazMessage' => '',
		'message' => '',
		'reasons' => [],
		'aiNcmAvailable' => false,
		'aiRetried' => false,
		'productsPushed' => false,
		'transient' => false,
		'scheduledAt' => 0,
		'emitAttempts' => 0,
		'pollAttempt' => 0,
		'stalled' => false,
		'stateChangedAt' => 0,
		'updatedAt' => 0,
	];

	/**
	 * @var array
	 */
	private $data;

	public function __construct( array $data = [] ) {
		$this->data = array_merge( self::DEFAULTS, array_intersect_key( $data, self::DEFAULTS ) );
	}

	public function toArray(): array {
		return $this->data;
	}

	/**
	 * The 44 digits of an NF-e access key. Focus NFe sends it with the "NFe" prefix of the XML Id attribute
	 * ("NFe4126..."); Correios, the order notes and the screens take the digits alone.
	 *
	 * @param mixed $value Access key as received.
	 */
	public static function accessKey( $value ): string {
		return is_scalar( $value ) ? (string) preg_replace( '/\D+/', '', (string) $value ) : '';
	}

	public function state(): string {
		return (string) $this->data['state'];
	}

	public function is( string ...$states ): bool {
		return in_array( $this->state(), $states, true );
	}

	/**
	 * Moves to a state. Leaving a state forgets what only made sense there (the message, the blocking reasons).
	 */
	public function setState( string $state, string $message = '' ): self {
		if ( $state !== $this->state() ) {
			$this->data['stateChangedAt'] = time();
		}

		$this->data['state'] = $state;
		$this->data['message'] = $message;
		$this->data['transient'] = false;

		if ( $state !== self::BLOCKED ) {
			$this->data['reasons'] = [];
		}

		if ( $state !== self::PROCESSING ) {
			$this->data['stalled'] = false;
		}

		return $this->touch();
	}

	/**
	 * Applies an invoice of the SquidCore API (InvoiceView) and moves to the matching state.
	 *
	 * @param array $invoice InvoiceView: id, status, numero, serie, chaveNfe, valorTotal, dataEmissao, cancelledAt,
	 *                       statusSefaz, mensagemSefaz, cancellableUntil, environment.
	 */
	public function applyRemote( array $invoice ): self {
		$status = strtoupper( (string) ( $invoice['status'] ?? '' ) );
		$id = (string) ( $invoice['id'] ?? '' );

		// A new invoice (after an error or a cancellation) starts its own authorization.
		if ( $id !== '' && $id !== $this->id() ) {
			$this->data['authorizedAt'] = '';
		}

		$this->data['id'] = $id !== '' ? $id : $this->id();
		$this->data['status'] = $status;
		$this->data['number'] = (string) ( $invoice['numero'] ?? '' );
		$this->data['series'] = (string) ( $invoice['serie'] ?? '' );
		$this->data['key'] = self::accessKey( $invoice['chaveNfe'] ?? '' );
		$this->data['environment'] = (string) ( $invoice['environment'] ?? '' );
		$this->data['total'] = (int) ( $invoice['valorTotal'] ?? 0 );
		$this->data['issuedAt'] = (string) ( $invoice['dataEmissao'] ?? '' );
		$this->data['cancelledAt'] = (string) ( $invoice['cancelledAt'] ?? '' );
		$this->data['cancellableUntil'] = (string) ( $invoice['cancellableUntil'] ?? '' );
		$this->data['sefazStatus'] = (string) ( $invoice['statusSefaz'] ?? '' );
		$this->data['sefazMessage'] = (string) ( $invoice['mensagemSefaz'] ?? '' );

		$state = self::REMOTE_STATES[ $status ] ?? self::PROCESSING;

		if ( $state === self::AUTHORIZED && $this->data['authorizedAt'] === '' ) {
			$this->data['authorizedAt'] = $this->data['issuedAt'] !== '' ? $this->data['issuedAt'] : gmdate( 'c' );
		}

		$message = in_array( $state, [ self::ERROR, self::DENIED ], true ) ? $this->data['sefazMessage'] : '';

		return $this->setState( $state, $message );
	}

	public function setBlocked( string $message, array $reasons, bool $aiNcmAvailable ): self {
		$this->setState( self::BLOCKED, $message );
		$this->data['reasons'] = array_values( array_map( [ $this, 'sanitizeReason' ], array_filter( $reasons, 'is_array' ) ) );
		$this->data['aiNcmAvailable'] = $aiNcmAvailable;

		return $this;
	}

	/**
	 * @param bool $transient Whether the same request may succeed later.
	 */
	public function setFailed( string $message, bool $transient = false ): self {
		$this->setState( self::FAILED, $message );
		$this->data['transient'] = $transient;

		return $this;
	}

	public function setScheduled( int $timestamp ): self {
		$this->setState( self::SCHEDULED );
		$this->data['scheduledAt'] = $timestamp;

		return $this;
	}

	public function message(): string {
		return (string) $this->data['message'];
	}

	/**
	 * @return array[] Blocking reasons (NfeBlockingReason) of the last attempt.
	 */
	public function reasons(): array {
		return (array) $this->data['reasons'];
	}

	/**
	 * Whether SquidCore can fill the missing NCM of the blocking reasons with AI.
	 */
	public function hasAiFillableReasons(): bool {
		foreach ( $this->reasons() as $reason ) {
			if ( ! empty( $reason['aiFillable'] ) ) {
				return true;
			}
		}

		return false;
	}

	public function aiNcmAvailable(): bool {
		return (bool) $this->data['aiNcmAvailable'];
	}

	public function aiRetried(): bool {
		return (bool) $this->data['aiRetried'];
	}

	public function setAiRetried( bool $retried ): self {
		$this->data['aiRetried'] = $retried;

		return $this;
	}

	/**
	 * Whether the last attempt came right after sending again the products of the blocking reasons.
	 */
	public function productsPushed(): bool {
		return (bool) $this->data['productsPushed'];
	}

	public function setProductsPushed( bool $pushed ): self {
		$this->data['productsPushed'] = $pushed;

		return $this;
	}

	public function isTransientFailure(): bool {
		return $this->is( self::FAILED ) && (bool) $this->data['transient'];
	}

	public function attention(): string {
		return (string) $this->data['attention'];
	}

	public function setAttention( string $reason ): self {
		$this->data['attention'] = $reason;

		return $this->touch();
	}

	/**
	 * Attention to raise once the invoice is authorized: the order was cancelled or refunded while it was issued.
	 */
	public function pendingAttention(): string {
		return (string) $this->data['pendingAttention'];
	}

	public function setPendingAttention( string $reason ): self {
		$this->data['pendingAttention'] = $reason;

		return $this;
	}

	public function id(): string {
		return (string) $this->data['id'];
	}

	public function number(): string {
		return (string) $this->data['number'];
	}

	public function series(): string {
		return (string) $this->data['series'];
	}

	public function key(): string {
		// Records saved before the key was normalized may still hold the "NFe" prefix.
		return self::accessKey( $this->data['key'] );
	}

	public function environment(): string {
		return (string) $this->data['environment'];
	}

	public function authorizedAt(): string {
		return (string) $this->data['authorizedAt'];
	}

	public function scheduledAt(): int {
		return (int) $this->data['scheduledAt'];
	}

	public function emitAttempts(): int {
		return (int) $this->data['emitAttempts'];
	}

	public function countEmitAttempt(): self {
		$this->data['emitAttempts'] = $this->emitAttempts() + 1;

		return $this;
	}

	public function resetEmitAttempts(): self {
		$this->data['emitAttempts'] = 0;

		return $this;
	}

	public function pollAttempt(): int {
		return (int) $this->data['pollAttempt'];
	}

	public function setPollAttempt( int $attempt ): self {
		$this->data['pollAttempt'] = max( 0, $attempt );

		return $this;
	}

	public function isStalled(): bool {
		return (bool) $this->data['stalled'];
	}

	public function setStalled( bool $stalled ): self {
		$this->data['stalled'] = $stalled;

		return $this->touch();
	}

	/**
	 * Whether an emission is running, or was interrupted long enough ago to start again.
	 */
	public function isEmittingStale( int $now ): bool {
		return $this->is( self::EMITTING ) && $now - (int) $this->data['stateChangedAt'] > self::EMITTING_STALE_AFTER;
	}

	/**
	 * Last moment to cancel the invoice: the deadline given by SquidCore, otherwise 24 hours after the authorization.
	 *
	 * @return int|null Unix time, null when the invoice is not authorized.
	 */
	public function cancelDeadline(): ?int {
		if ( ! $this->is( self::AUTHORIZED ) ) {
			return null;
		}

		$until = strtotime( (string) $this->data['cancellableUntil'] );

		if ( $until ) {
			return $until;
		}

		$authorized = strtotime( $this->authorizedAt() );

		return $authorized ? $authorized + self::CANCEL_WINDOW_HOURS * HOUR_IN_SECONDS : null;
	}

	public function canCancel( int $now ): bool {
		$deadline = $this->cancelDeadline();

		return $deadline !== null && $now < $deadline;
	}

	/**
	 * Whether a new emission may start now: never started, or ended without an active invoice.
	 */
	public function canEmit( int $now ): bool {
		return $this->is( '', self::SCHEDULED, self::BLOCKED, self::ERROR, self::DENIED, self::FAILED, self::CANCELLED, self::SKIPPED )
			|| $this->isEmittingStale( $now );
	}

	private function touch(): self {
		$this->data['updatedAt'] = time();

		return $this;
	}

	/**
	 * Keeps only the known fields of a blocking reason (NfeBlockingReason), as plain text. Any code is accepted:
	 * the message comes ready from SquidCore.
	 */
	private function sanitizeReason( array $reason ): array {
		$clean = [
			'code' => sanitize_text_field( (string) ( $reason['code'] ?? '' ) ),
			'target' => sanitize_key( (string) ( $reason['target'] ?? '' ) ),
			'message' => sanitize_text_field( (string) ( $reason['message'] ?? '' ) ),
			'aiFillable' => ! empty( $reason['aiFillable'] ),
		];

		if ( isset( $reason['entityLabel'] ) ) {
			$clean['entityLabel'] = sanitize_text_field( (string) $reason['entityLabel'] );
		}

		if ( isset( $reason['wcProductId'] ) ) {
			$clean['wcProductId'] = (int) $reason['wcProductId'];
		}

		// SquidCore writes the text of every code, the new ones included; the code is shown only when it does not.
		if ( $clean['message'] === '' ) {
			/* translators: %s: code of the pending item, e.g. "woo_order.item_zero_price". */
			$clean['message'] = sprintf( __( 'SquidCore reported a pending item (%s). Check the order in SquidCore.', 'infixs-squidcore-erp' ), $clean['code'] !== '' ? $clean['code'] : '?' );
		}

		return $clean;
	}
}
