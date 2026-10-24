<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Which orders get an invoice (NF-e) automatically, and whether an order is still paid when the emission runs.
 *
 * The manual emission skips these rules: the store owner decides.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceEligibility {

	/**
	 * Trigger of the check made right before the automatic emission runs.
	 */
	public const TRIGGER_EMIT = 'emit';

	/**
	 * Statuses that rule the emission out even when the order was paid once.
	 */
	private const UNPAID_STATUSES = [ 'pending', 'failed', 'cancelled', 'refunded', 'checkout-draft', 'trash' ];

	/**
	 * Why the order does not get an invoice automatically. Asked when the emission is queued.
	 *
	 * @param string $trigger What asks for the emission (e.g. "payment").
	 *
	 * @return string|null Message for the order note, null when the order gets the invoice.
	 */
	public function automaticSkipReason( \WC_Order $order, string $trigger ): ?string {
		if ( $order->get_meta( 'has_sub_order', true ) ) {
			return __( 'Automatic invoice skipped: the order was split into marketplace sub-orders.', 'infixs-squidcore-erp' );
		}

		if ( $order->get_parent_id() > 0 ) {
			return __( 'Automatic invoice skipped: this is a marketplace sub-order.', 'infixs-squidcore-erp' );
		}

		if ( (float) $order->get_total() <= 0 ) {
			return __( 'Automatic invoice skipped: the order total is zero.', 'infixs-squidcore-erp' );
		}

		if ( $this->billingCountry( $order ) !== 'BR' ) {
			return __( 'Automatic invoice skipped: the customer is outside Brazil. You can still issue it manually.', 'infixs-squidcore-erp' );
		}

		/**
		 * Filters whether a paid order gets its invoice (NF-e) issued automatically.
		 *
		 * @since 1.0.0
		 *
		 * @param bool      $should_emit Whether to issue the invoice.
		 * @param \WC_Order $order       Order.
		 * @param string    $trigger     What asks for the emission: "payment" or "correios".
		 */
		if ( ! apply_filters( 'infixs_squidcore_invoice_should_emit', true, $order, $trigger ) ) {
			return __( 'Automatic invoice skipped by a customization of the store.', 'infixs-squidcore-erp' );
		}

		return $this->filteredSkipReason( $order, $trigger );
	}

	/**
	 * Why the queued automatic emission no longer runs, asked right before it: what changed in the order since it
	 * was queued (another plugin issued its invoice meanwhile, for example). Only the
	 * `infixs_squidcore_invoice_skip_reason` filter is asked again.
	 *
	 * @return string|null Message for the order note, null when the emission goes on.
	 */
	public function emitTimeSkipReason( \WC_Order $order ): ?string {
		return $this->filteredSkipReason( $order, self::TRIGGER_EMIT );
	}

	private function filteredSkipReason( \WC_Order $order, string $trigger ): ?string {
		/**
		 * Filters why an order does not get its invoice (NF-e) issued automatically.
		 *
		 * @since 1.3.0
		 *
		 * @param string|null $reason  Message for the order note, null when nothing rules the emission out.
		 * @param \WC_Order   $order   Order.
		 * @param string      $trigger "payment" or "correios" when the emission is queued (or asked about), "emit"
		 *                             right before the queued emission runs.
		 */
		$reason = apply_filters( 'infixs_squidcore_invoice_skip_reason', null, $order, $trigger );

		return is_string( $reason ) && trim( $reason ) !== '' ? $reason : null;
	}

	/**
	 * Whether the order was paid and is still a sale. The status alone does not tell: other plugins (Correios
	 * Automático, for example) move paid orders to statuses of their own.
	 */
	public function isStillPaid( \WC_Order $order ): bool {
		return $order->get_date_paid() !== null && ! in_array( $order->get_status(), self::UNPAID_STATUSES, true );
	}

	private function billingCountry( \WC_Order $order ): string {
		$country = $order->get_billing_country();

		if ( $country === '' && function_exists( 'WC' ) && WC()->countries ) {
			$country = WC()->countries->get_base_country();
		}

		return strtoupper( $country );
	}
}
