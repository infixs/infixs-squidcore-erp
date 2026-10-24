<?php

namespace Infixs\SquidCore\Integrations\WebmaniaBR;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps SquidCore from issuing a second invoice (NF-e) automatically for an order WebmaniaBR already invoiced, both
 * when the emission is queued and right before it runs. The manual emission stays free: the store owner decides.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class WebmaniaInvoiceBridge {

	/**
	 * @var WebmaniaInvoiceHistory
	 */
	protected $history;

	public function __construct( WebmaniaInvoiceHistory $history ) {
		$this->history = $history;
	}

	public function register(): void {
		add_filter( 'infixs_squidcore_invoice_skip_reason', [ $this, 'skipReason' ], 10, 3 );
	}

	/**
	 * @param string|null $reason  Reason given by an earlier callback, kept as is.
	 * @param \WC_Order   $order
	 * @param string      $trigger payment, correios or emit.
	 *
	 * @return string|null Text of the order note, null when WebmaniaBR did not invoice the order.
	 */
	public function skipReason( $reason, $order, $trigger = '' ) {
		if ( $reason !== null || ! $order instanceof \WC_Order ) {
			return $reason;
		}

		$invoice = $this->history->blocksAutomaticEmission( $order );

		if ( $invoice === null ) {
			return null;
		}

		if ( $invoice['number'] === '' || $invoice['series'] === '' ) {
			return __( 'Automatic invoice skipped: this order already has an NF-e issued by WebmaniaBR. To issue another one, use the Issue now button.', 'infixs-squidcore-erp' );
		}

		return sprintf(
			/* translators: 1: NF-e number, 2: NF-e series. */
			__( 'Automatic invoice skipped: this order already has NF-e no. %1$s, series %2$s, issued by WebmaniaBR. To issue another one, use the Issue now button.', 'infixs-squidcore-erp' ),
			$invoice['number'],
			$invoice['series']
		);
	}
}
