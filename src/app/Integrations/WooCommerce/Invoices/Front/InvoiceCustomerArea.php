<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Front;

use Infixs\SquidCore\AvelPress\View\View;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceApi;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceSettings;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceUrls;

defined( 'ABSPATH' ) || exit;

/**
 * The invoice for the customer: a "Invoice" section in the order details (My Account and thank-you page) and the
 * DANFE/XML links in the completed order and customer invoice emails.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceCustomerArea {

	/**
	 * Customer emails that carry the invoice links.
	 */
	public const EMAIL_IDS = [ 'customer_completed_order', 'customer_invoice' ];

	/**
	 * @var InvoiceService
	 */
	protected $service;

	/**
	 * @var InvoiceSettings
	 */
	protected $settings;

	/**
	 * @var View
	 */
	protected $view;

	public function __construct( InvoiceService $service, InvoiceSettings $settings, View $view ) {
		$this->service = $service;
		$this->settings = $settings;
		$this->view = $view;
	}

	public function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'renderOrderSection' ] );
		add_action( 'woocommerce_email_order_meta', [ $this, 'renderEmailLinks' ], 20, 4 );
	}

	/**
	 * @param \WC_Order|mixed $order
	 */
	public function renderOrderSection( $order ): void {
		$data = $this->viewData( $order );

		if ( $data ) {
			$this->view->make( 'front.invoice-order-section', $data );
		}
	}

	/**
	 * @param \WC_Order|mixed $order
	 * @param bool            $sentToAdmin
	 * @param bool            $plainText
	 * @param \WC_Email|mixed $email
	 */
	public function renderEmailLinks( $order, $sentToAdmin = false, $plainText = false, $email = null ): void {
		if ( $sentToAdmin || ! is_object( $email ) || ! in_array( $email->id ?? '', self::EMAIL_IDS, true ) ) {
			return;
		}

		if ( ! $this->settings->linksInCustomerEmail() ) {
			return;
		}

		$data = $this->viewData( $order );

		if ( $data ) {
			$this->view->make( $plainText ? 'front.invoice-email-links-plain' : 'front.invoice-email-links', $data );
		}
	}

	/**
	 * @param \WC_Order|mixed $order
	 *
	 * @return array|null Null when the customer may not see the invoice.
	 */
	private function viewData( $order ): ?array {
		if ( ! $order instanceof \WC_Order || ! $this->service->isVisibleToCustomer( $order ) ) {
			return null;
		}

		$record = $this->service->find( $order );

		return [
			'number' => $record->number(),
			'series' => $record->series(),
			'danfe_url' => InvoiceUrls::customerDownload( $order, InvoiceApi::FILE_DANFE ),
			'xml_url' => InvoiceUrls::customerDownload( $order, InvoiceApi::FILE_XML ),
		];
	}
}
