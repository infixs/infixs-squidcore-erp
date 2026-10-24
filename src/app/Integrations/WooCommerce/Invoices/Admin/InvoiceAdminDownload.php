<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceDownloadResponder;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceUrls;

defined( 'ABSPATH' ) || exit;

/**
 * DANFE and XML downloads for the shop staff (admin-post). Cancelled invoices stay downloadable.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceAdminDownload {

	/**
	 * @var InvoiceDownloadResponder
	 */
	protected $responder;

	public function __construct( InvoiceDownloadResponder $responder ) {
		$this->responder = $responder;
	}

	public function register(): void {
		add_action( 'admin_post_' . InvoiceUrls::ADMIN_DOWNLOAD_ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() below.
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$file = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : '';
		$invoice_id = isset( $_GET['nfe_id'] ) ? sanitize_text_field( wp_unslash( $_GET['nfe_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		check_admin_referer( InvoiceUrls::adminNonceAction( $order_id ) );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to download invoices.', 'infixs-squidcore-erp' ), '', [ 'response' => 403 ] );
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'infixs-squidcore-erp' ), '', [ 'response' => 404 ] );
		}

		$this->responder->serve( $order, $file, $invoice_id );
	}
}
