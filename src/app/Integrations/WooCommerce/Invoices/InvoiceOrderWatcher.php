<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit\PlanLimitLabels;

defined( 'ABSPATH' ) || exit;

/**
 * Reacts to orders cancelled or refunded after their invoice was queued or issued.
 *
 * A queued emission is dropped, and so is an emission the plan limit refused, which "Issue the pending invoices" would
 * otherwise issue. An authorized invoice is flagged for the store owner, who cancels it (up to 24 hours after the
 * authorization) or issues a return invoice in SquidCore; nothing is cancelled automatically.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceOrderWatcher {

	/**
	 * @var InvoiceService
	 */
	protected $service;

	public function __construct( InvoiceService $service ) {
		$this->service = $service;
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'onCancelled' ], 10, 2 );
		add_action( 'woocommerce_order_status_refunded', [ $this, 'onRefunded' ], 10, 2 );
		add_action( 'woocommerce_order_partially_refunded', [ $this, 'onPartiallyRefunded' ], 10, 1 );
	}

	/**
	 * @param int $order_id
	 * @param \WC_Order|null $order
	 */
	public function onCancelled( $order_id, $order = null ): void {
		$this->handle( $order_id, $order, InvoiceRecord::ATTENTION_ORDER_CANCELLED, __( 'Automatic invoice skipped: the order was cancelled.', 'infixs-squidcore-erp' ) );
	}

	/**
	 * @param int $order_id
	 * @param \WC_Order|null $order
	 */
	public function onRefunded( $order_id, $order = null ): void {
		$this->handle( $order_id, $order, InvoiceRecord::ATTENTION_ORDER_REFUNDED, __( 'Automatic invoice skipped: the order was refunded.', 'infixs-squidcore-erp' ) );
	}

	/**
	 * @param int $order_id
	 */
	public function onPartiallyRefunded( $order_id ): void {
		$this->handle( $order_id, null, InvoiceRecord::ATTENTION_PARTIAL_REFUND, '' );
	}

	/**
	 * @param mixed $order_id
	 * @param \WC_Order|null $order
	 * @param string $attention Attention reason for an authorized invoice.
	 * @param string $skipReason Note for a queued emission; blank keeps it queued (and an emission the plan limit
	 *                           refused among the pending invoices).
	 */
	private function handle( $order_id, $order, string $attention, string $skipReason ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		$record = $order instanceof \WC_Order ? $this->service->find( $order ) : null;

		if ( ! $record ) {
			return;
		}

		if ( $record->is( InvoiceRecord::SCHEDULED ) && $skipReason !== '' ) {
			$this->service->skip( $order, $skipReason );
			return;
		}

		if ( $record->isPlanLimitFailure() && $skipReason !== '' ) {
			$this->service->skip( $order, PlanLimitLabels::saleEnded( $order->get_status() ) );
			return;
		}

		if ( $record->is( InvoiceRecord::EMITTING, InvoiceRecord::PROCESSING ) ) {
			$this->service->deferAttention( $order, $record, $attention );
			return;
		}

		if ( $record->is( InvoiceRecord::AUTHORIZED ) && $record->attention() !== $attention ) {
			$this->service->flagAttention( $order, $record, $attention );
		}
	}
}
