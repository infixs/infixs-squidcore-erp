<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Front;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceApi;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceDownloadResponder;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceUrls;

defined( 'ABSPATH' ) || exit;

/**
 * DANFE and XML downloads for the customer: `?infixs-squidcore-invoice=danfe|xml&order={id}&key={order key}`.
 *
 * The order key authorizes the download, so the email links work without logging in. Only an authorized invoice
 * visible to the customer is served.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceCustomerDownload {

	/**
	 * @var InvoiceDownloadResponder
	 */
	protected $responder;

	/**
	 * @var InvoiceService
	 */
	protected $service;

	public function __construct( InvoiceDownloadResponder $responder, InvoiceService $service ) {
		$this->responder = $responder;
		$this->service = $service;
	}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'handle' ], 5 );
	}

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authorized by the order key.
		if ( ! isset( $_GET[ InvoiceUrls::CUSTOMER_QUERY_VAR ] ) ) {
			return;
		}

		$file = sanitize_key( wp_unslash( $_GET[ InvoiceUrls::CUSTOMER_QUERY_VAR ] ) );
		$order_id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $file, [ InvoiceApi::FILE_DANFE, InvoiceApi::FILE_XML ], true ) ) {
			wp_die( esc_html__( 'Unknown invoice file.', 'infixs-squidcore-erp' ), '', [ 'response' => 400 ] );
		}

		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;

		// Refunds have no key of their own; an unknown order and a wrong key look the same.
		if ( ! $order instanceof \WC_Order || $key === '' || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			wp_die( esc_html__( 'This invoice link is not valid.', 'infixs-squidcore-erp' ), '', [ 'response' => 403 ] );
		}

		if ( ! $this->service->isVisibleToCustomer( $order ) ) {
			wp_die( esc_html__( 'The invoice of this order is not available.', 'infixs-squidcore-erp' ), '', [ 'response' => 404 ] );
		}

		$this->responder->serve( $order, $file );
	}
}
