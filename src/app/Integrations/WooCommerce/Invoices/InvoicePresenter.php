<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Invoice of an order as the admin screens and the REST API show it: the record, its texts and what can be done.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoicePresenter {

	/**
	 * @var InvoiceRepository
	 */
	protected $repository;

	/**
	 * @var SefazRejectionAdvice
	 */
	protected $advice;

	public function __construct( InvoiceRepository $repository, SefazRejectionAdvice $advice ) {
		$this->repository = $repository;
		$this->advice = $advice;
	}

	/**
	 * @return array
	 */
	public function present( \WC_Order $order, ?InvoiceRecord $record ): array {
		$record = $record ?? new InvoiceRecord();
		$now = time();
		$deadline = $record->cancelDeadline();
		$has_files = $record->is( InvoiceRecord::AUTHORIZED, InvoiceRecord::CANCELLED ) && $record->key() !== '';

		return [
			'orderId' => $order->get_id(),
			'orderNumber' => (string) $order->get_order_number(),
			'orderEditUrl' => $order->get_edit_order_url(),
			'customerName' => trim( $order->get_formatted_billing_full_name() ),
			'state' => $record->state() !== '' ? $record->state() : 'none',
			'stateLabel' => InvoiceLabels::state( $record->state() ),
			'message' => $record->message(),
			'advice' => $this->advice->forRecord( $record ),
			'reasons' => array_map( [ $this, 'presentReason' ], $record->reasons() ),
			'aiNcmAvailable' => $record->aiNcmAvailable(),
			'stalled' => $record->isStalled(),
			'attention' => $record->attention(),
			'attentionMessage' => InvoiceLabels::attention( $record->attention() ),
			'scheduledAt' => $record->is( InvoiceRecord::SCHEDULED ) && $record->scheduledAt() ? gmdate( 'c', $record->scheduledAt() ) : null,
			'invoice' => $record->id() !== '' ? [
				'id' => $record->id(),
				'number' => $record->number(),
				'series' => $record->series(),
				'key' => $record->key(),
				'environment' => $record->environment(),
				'authorizedAt' => $record->authorizedAt() !== '' ? $record->authorizedAt() : null,
			] : null,
			'cancelDeadline' => $deadline ? gmdate( 'c', $deadline ) : null,
			'canEmit' => $record->canEmit( $now ),
			'canRefresh' => $record->id() !== '' || $record->is( InvoiceRecord::PROCESSING, InvoiceRecord::FAILED ),
			'canCancel' => $record->canCancel( $now ),
			'downloads' => $has_files
				? $this->downloads( $order->get_id(), '', $record->is( InvoiceRecord::CANCELLED ) )
				: null,
		];
	}

	/**
	 * Blocking reason with the name and edit link of its WooCommerce product, when it is about one (a variation
	 * links to its parent).
	 *
	 * @param array $reason
	 *
	 * @return array The reason plus `productName` and `productEditUrl`, blank when not about a product.
	 */
	private function presentReason( array $reason ): array {
		$product = ! empty( $reason['wcProductId'] ) ? wc_get_product( (int) $reason['wcProductId'] ) : null;

		$reason['productName'] = $product ? $product->get_name() : '';
		$reason['productEditUrl'] = $product
			? (string) get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(), 'raw' )
			: '';

		return $reason;
	}

	/**
	 * Invoice of the SquidCore list (InvoiceView with wcOrderId, orderNumber and recipientName), with the links of
	 * the store. The list keeps every invoice of an order; only the one the order holds now can be refreshed or
	 * issued again, since those actions act on the order, and only that one gets the advice on its rejection.
	 *
	 * @param array $row
	 *
	 * @return array
	 */
	public function presentRemote( array $row ): array {
		$order_id = (int) ( $row['wcOrderId'] ?? 0 );
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$status = strtoupper( (string) ( $row['status'] ?? '' ) );
		$invoice_id = (string) ( $row['id'] ?? '' );
		$has_files = $order_id > 0 && in_array( $status, [ 'AUTHORIZED', 'CANCELLED' ], true );
		$until = strtotime( (string) ( $row['cancellableUntil'] ?? '' ) );
		$record = $order instanceof \WC_Order ? $this->repository->find( $order ) : null;
		$current = $record !== null && $invoice_id !== '' && $record->id() === $invoice_id;

		return [
			'id' => $invoice_id,
			'status' => $status,
			'number' => (string) ( $row['numero'] ?? '' ),
			'series' => (string) ( $row['serie'] ?? '' ),
			'key' => InvoiceRecord::accessKey( $row['chaveNfe'] ?? '' ),
			'total' => (int) ( $row['valorTotal'] ?? 0 ),
			'issuedAt' => $row['dataEmissao'] ?? null,
			'cancelledAt' => $row['cancelledAt'] ?? null,
			'cancellableUntil' => $row['cancellableUntil'] ?? null,
			'sefazMessage' => (string) ( $row['mensagemSefaz'] ?? '' ),
			// An older invoice of the order was already replaced: nothing left to fix there, only its SEFAZ message.
			'advice' => $current ? $this->advice->forRemote( $row ) : null,
			'environment' => (string) ( $row['environment'] ?? '' ),
			'wcOrderId' => $order_id,
			'orderNumber' => (string) ( $row['orderNumber'] ?? $order_id ),
			'recipientName' => (string) ( $row['recipientName'] ?? '' ),
			'orderEditUrl' => $order instanceof \WC_Order ? $order->get_edit_order_url() : null,
			'canCancel' => $status === 'AUTHORIZED' && empty( $row['cancelWindowExpired'] ) && ( ! $until || time() < $until ),
			'canRefresh' => $current && in_array( $status, [ 'DRAFT', 'PROCESSING' ], true ),
			'canRetry' => $current && in_array( $status, [ 'ERROR', 'DENIED' ], true ) && $record->canEmit( time() ),
			'downloads' => $has_files ? $this->downloads( $order_id, $invoice_id, $status === 'CANCELLED' ) : null,
		];
	}

	/**
	 * Admin links of the invoice files; a cancelled invoice also has the XML of its cancellation event.
	 *
	 * @param string $invoiceId SquidCore id of the invoice; blank for the current one of the order.
	 *
	 * @return array{danfe: string, xml: string, cancellationXml?: string}
	 */
	private function downloads( int $orderId, string $invoiceId, bool $cancelled ): array {
		$links = [
			'danfe' => InvoiceUrls::adminDownload( $orderId, InvoiceApi::FILE_DANFE, $invoiceId ),
			'xml' => InvoiceUrls::adminDownload( $orderId, InvoiceApi::FILE_XML, $invoiceId ),
		];

		if ( $cancelled ) {
			$links['cancellationXml'] = InvoiceUrls::adminDownload( $orderId, InvoiceApi::FILE_CANCELLATION_XML, $invoiceId );
		}

		return $links;
	}
}
