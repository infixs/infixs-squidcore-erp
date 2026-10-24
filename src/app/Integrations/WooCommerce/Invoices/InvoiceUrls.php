<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Addresses of the invoice files served by the store. The files come from SquidCore through the store, so the
 * access token never reaches the browser.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceUrls {

	public const ADMIN_DOWNLOAD_ACTION = 'infixs_squidcore_invoice_download';

	/**
	 * Query argument of the customer download; its value is the file (danfe or xml).
	 */
	public const CUSTOMER_QUERY_VAR = 'infixs-squidcore-invoice';

	/**
	 * Download for the shop staff (admin-post, with a nonce of the order).
	 *
	 * Returns the raw URL (plain "&"), because it also travels in JSON to the dashboard; templates escape it with
	 * esc_url() when printing. wp_nonce_url() would return it HTML-escaped ("&amp;") and break the JSON links.
	 *
	 * @param string $file InvoiceApi::FILE_DANFE, FILE_XML or FILE_CANCELLATION_XML.
	 * @param string $invoiceId SquidCore id of an older invoice of the order; blank for the current one.
	 */
	public static function adminDownload( int $orderId, string $file, string $invoiceId = '' ): string {
		return add_query_arg( array_filter( [
			'action' => self::ADMIN_DOWNLOAD_ACTION,
			'order_id' => $orderId,
			'file' => $file,
			'nfe_id' => $invoiceId,
			'_wpnonce' => wp_create_nonce( self::adminNonceAction( $orderId ) ),
		] ), admin_url( 'admin-post.php' ) );
	}

	public static function adminNonceAction( int $orderId ): string {
		return self::ADMIN_DOWNLOAD_ACTION . '_' . $orderId;
	}

	/**
	 * Download for the customer, authorized by the order key, so it works from the email without logging in.
	 *
	 * @param string $file InvoiceApi::FILE_DANFE or InvoiceApi::FILE_XML.
	 */
	public static function customerDownload( \WC_Order $order, string $file ): string {
		return add_query_arg( [
			self::CUSTOMER_QUERY_VAR => $file,
			'order' => $order->get_id(),
			'key' => $order->get_order_key(),
		], home_url( '/' ) );
	}
}
