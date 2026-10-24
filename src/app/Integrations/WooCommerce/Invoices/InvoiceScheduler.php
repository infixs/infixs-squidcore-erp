<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Queues the automatic invoice (NF-e) emission when an order is paid.
 *
 * Listens to the payment and to the change to a paid status (orders created in the admin, boleto and Pix confirmed
 * later, cash on delivery). Both fire for most payments; the first one queues the emission and the second finds the
 * record already there.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceScheduler {

	/**
	 * Shortest wait before the emission, in seconds: lets the payment request finish first.
	 */
	private const MIN_DELAY = 10;

	/**
	 * @var InvoiceService
	 */
	protected $service;

	/**
	 * @var InvoiceSettings
	 */
	protected $settings;

	/**
	 * @var InvoiceEligibility
	 */
	protected $eligibility;

	public function __construct( InvoiceService $service, InvoiceSettings $settings, InvoiceEligibility $eligibility ) {
		$this->service = $service;
		$this->settings = $settings;
		$this->eligibility = $eligibility;
	}

	public function register(): void {
		add_action( 'woocommerce_payment_complete', [ $this, 'onPaymentComplete' ] );
		add_action( 'woocommerce_order_status_changed', [ $this, 'onStatusChanged' ], 10, 4 );
	}

	/**
	 * @param int $order_id
	 */
	public function onPaymentComplete( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			$this->maybeSchedule( $order );
		}
	}

	/**
	 * @param int $order_id
	 * @param string $from
	 * @param string $to
	 * @param \WC_Order|null $order
	 */
	public function onStatusChanged( $order_id, $from, $to, $order = null ): void {
		if ( ! in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		if ( $order instanceof \WC_Order && $order->is_paid() ) {
			$this->maybeSchedule( $order );
		}
	}

	private function maybeSchedule( \WC_Order $order ): void {
		if ( ! $this->settings->isEnabled() || $this->service->find( $order ) ) {
			return;
		}

		$skip_reason = $this->eligibility->automaticSkipReason( $order, 'payment' );

		if ( $skip_reason !== null ) {
			$this->service->skip( $order, $skip_reason );
			return;
		}

		$this->service->schedule( $order, $this->emitAt( $order ) );
	}

	private function emitAt( \WC_Order $order ): int {
		$earliest = time() + self::MIN_DELAY;
		$timestamp = max( $earliest, time() + $this->settings->delayHours() * HOUR_IN_SECONDS );

		/**
		 * Filters when the automatic invoice emission of a paid order runs.
		 *
		 * @since 1.0.0
		 *
		 * @param int       $timestamp Unix time of the emission (now plus the configured delay).
		 * @param \WC_Order $order     Order.
		 */
		return max( $earliest, (int) apply_filters( 'infixs_squidcore_invoice_emit_at', $timestamp, $order ) );
	}
}
