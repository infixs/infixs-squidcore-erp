<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

use Infixs\SquidCore\Services\ConnectionService;
use Infixs\SquidCore\Support\ApiErrorMessage;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the DANFE (PDF) or the XML of an order invoice, fetched from SquidCore.
 *
 * Only a PDF or an XML answer is passed on, so the store never serves anything else from SquidCore on its own
 * domain. The DANFE opens in the browser; the XML is downloaded.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceDownloadResponder {

	/**
	 * Content types accepted from SquidCore for each file; the first one is sent to the browser.
	 */
	private const CONTENT_TYPES = [
		InvoiceApi::FILE_DANFE => [ 'application/pdf' ],
		InvoiceApi::FILE_XML => [ 'application/xml', 'text/xml' ],
		InvoiceApi::FILE_CANCELLATION_XML => [ 'application/xml', 'text/xml' ],
	];

	/**
	 * End of the file name after the access key.
	 */
	private const SUFFIXES = [
		InvoiceApi::FILE_DANFE => '.pdf',
		InvoiceApi::FILE_XML => '.xml',
		InvoiceApi::FILE_CANCELLATION_XML => '-cancelamento.xml',
	];

	/**
	 * Digits of an NF-e access key.
	 */
	private const KEY_LENGTH = 44;

	/**
	 * @var InvoiceApi
	 */
	protected $api;

	/**
	 * @var InvoiceRepository
	 */
	protected $repository;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	public function __construct( InvoiceApi $api, InvoiceRepository $repository, ConnectionService $connection ) {
		$this->api = $api;
		$this->repository = $repository;
		$this->connection = $connection;
	}

	/**
	 * Sends the file and ends the request; a failure ends it with an error page.
	 *
	 * @param string $file InvoiceApi::FILE_DANFE, FILE_XML or FILE_CANCELLATION_XML.
	 * @param string $invoiceId SquidCore id of an older invoice of the order; blank for the current one.
	 */
	public function serve( \WC_Order $order, string $file, string $invoiceId = '' ): void {
		$response = $this->prepare( $order, $file, $invoiceId );

		if ( is_wp_error( $response ) ) {
			$data = (array) $response->get_error_data();

			wp_die(
				esc_html( $response->get_error_message() ),
				esc_html__( 'Invoice', 'infixs-squidcore-erp' ),
				[
					'response' => (int) ( $data['status'] ?? 502 ),
					'back_link' => (bool) is_admin(),
				]
			);
		}

		$this->send( $response );
	}

	/**
	 * Fetches the file and builds the answer to the browser.
	 *
	 * @param string $file InvoiceApi::FILE_DANFE, FILE_XML or FILE_CANCELLATION_XML.
	 * @param string $invoiceId SquidCore id of an older invoice of the order; blank for the current one.
	 *
	 * @return array{headers: array<string, string>, body: string}|\WP_Error
	 */
	public function prepare( \WC_Order $order, string $file, string $invoiceId = '' ) {
		if ( ! isset( self::CONTENT_TYPES[ $file ] ) ) {
			return new \WP_Error( 'invalid_invoice_file', __( 'Unknown invoice file.', 'infixs-squidcore-erp' ), [ 'status' => 400 ] );
		}

		$download = $this->api->interactive()->download( $order->get_id(), $file, $invoiceId );

		if ( is_wp_error( $download ) ) {
			return new \WP_Error( 'invoice_download_failed', ApiErrorMessage::describeInvoice( $download, $this->connection->invoicePermission() ), [
				'status' => ApiErrorMessage::status( $download ) === 404 ? 404 : 502,
			] );
		}

		if ( ! in_array( $download['contentType'], self::CONTENT_TYPES[ $file ], true ) || $download['body'] === '' ) {
			return new \WP_Error( 'invoice_download_unexpected', __( 'SquidCore returned an unexpected file. Try again in a few minutes.', 'infixs-squidcore-erp' ), [ 'status' => 502 ] );
		}

		$disposition = $file === InvoiceApi::FILE_DANFE ? 'inline' : 'attachment';

		return [
			'headers' => [
				'Content-Type' => self::CONTENT_TYPES[ $file ][0],
				'Content-Disposition' => sprintf( '%s; filename="%s"', $disposition, $this->filename( $order, $file, $invoiceId, $download['filename'] ) ),
				'Content-Length' => (string) strlen( $download['body'] ),
				'X-Content-Type-Options' => 'nosniff',
				'Cache-Control' => 'private, no-store, max-age=0',
				'X-Robots-Tag' => 'noindex, nofollow',
				'Referrer-Policy' => 'no-referrer',
			],
			'body' => $download['body'],
		];
	}

	/**
	 * @param array{headers: array<string, string>, body: string} $response
	 */
	protected function send( array $response ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );

		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file.
		exit;
	}

	/**
	 * NFe-{access key}.pdf|.xml|-cancelamento.xml. The key comes from the name given by SquidCore, otherwise from the record of the
	 * current invoice; the order number when neither has one.
	 */
	private function filename( \WC_Order $order, string $file, string $invoiceId, string $upstreamName ): string {
		$key = preg_replace( '/\D/', '', pathinfo( $upstreamName, PATHINFO_FILENAME ) );

		if ( strlen( $key ) !== self::KEY_LENGTH ) {
			$record = $this->repository->find( $order );
			$is_current = $record && ( $invoiceId === '' || $invoiceId === $record->id() );
			$key = $is_current ? preg_replace( '/\D/', '', $record->key() ) : '';
		}

		if ( strlen( $key ) !== self::KEY_LENGTH ) {
			$key = sanitize_file_name( (string) $order->get_order_number() );
		}

		return 'NFe-' . $key . self::SUFFIXES[ $file ];
	}
}
