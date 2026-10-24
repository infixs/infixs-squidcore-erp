<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

use Infixs\SquidCore\Support\SquidCoreClient;

defined( 'ABSPATH' ) || exit;

/**
 * Invoice (NF-e) endpoints of the SquidCore API for WooCommerce orders. Orders are identified by their WooCommerce id.
 *
 * Every method returns the decoded `data` of the answer, or the WP_Error of the client (with the HTTP status and
 * the decoded error body, whose `code` tells what went wrong).
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceApi {

	private const BASE = 'integrations/apps/woocommerce';

	public const FILE_DANFE = 'danfe';

	public const FILE_XML = 'xml';

	/**
	 * XML of the cancellation event of a cancelled invoice (`nfe/xml?tipo=cancelamento`).
	 */
	public const FILE_CANCELLATION_XML = 'xml-cancelamento';

	/**
	 * Path under the order and query arguments of each file.
	 */
	private const FILE_ROUTES = [
		self::FILE_DANFE => [ 'nfe/danfe', [] ],
		self::FILE_XML => [ 'nfe/xml', [] ],
		self::FILE_CANCELLATION_XML => [ 'nfe/xml', [ 'tipo' => 'cancelamento' ] ],
	];

	/**
	 * @var SquidCoreClient
	 */
	protected $client;

	public function __construct( SquidCoreClient $client ) {
		$this->client = $client;
	}

	/**
	 * Same API failing fast (one attempt per request), for calls a person is waiting for.
	 */
	public function interactive(): self {
		return new self( $this->client->withMaxAttempts( 1 ) );
	}

	/**
	 * Same API with a shorter wait for the file downloads.
	 */
	public function withDownloadTimeout( int $seconds ): self {
		return new self( $this->client->withDownloadTimeout( $seconds ) );
	}

	/**
	 * Issues the invoice of the order. Idempotent: an active invoice comes back with `created` false.
	 *
	 * @param array $body Optional `order`, `items` and `autoFillNcmWithAi`.
	 *
	 * @return array|\WP_Error {created, invoice}
	 */
	public function emit( int $orderId, array $body = [] ) {
		return $this->data( $this->client->post( $this->orderPath( $orderId, 'nfe' ), $body ) );
	}

	/**
	 * Current invoice of the order; SquidCore refreshes a pending one from the provider.
	 *
	 * @return array|\WP_Error {wcOrderId, invoice, history, nextPollAfterSeconds, ...}
	 */
	public function show( int $orderId ) {
		return $this->data( $this->client->get( $this->orderPath( $orderId, 'nfe' ) ) );
	}

	/**
	 * @param array $params page, perPage, status, search, wcOrderId.
	 *
	 * @return array|\WP_Error The whole answer: {data, currentPage, perPage, total, lastPage}.
	 */
	public function list( array $params ) {
		return $this->client->get( self::BASE . '/nfe', array_filter( $params, function ( $value ) {
			return $value !== null && $value !== '';
		} ) );
	}

	/**
	 * @param string $justificativa Reason for SEFAZ, 15 to 255 characters.
	 * @param string $invoiceId Blank cancels the current invoice of the order.
	 *
	 * @return array|\WP_Error {invoice}
	 */
	public function cancel( int $orderId, string $justificativa, string $invoiceId = '' ) {
		$body = [ 'justificativa' => $justificativa ];

		if ( $invoiceId !== '' ) {
			$body['nfeId'] = $invoiceId;
		}

		return $this->data( $this->client->post( $this->orderPath( $orderId, 'nfe/cancel' ), $body ) );
	}

	/**
	 * Asks the AI of SquidCore to fill the missing NCM of the order products.
	 *
	 * @param int[] $productIds WooCommerce product ids; empty means every line without NCM.
	 *
	 * @return array|\WP_Error {filled, failed}
	 */
	public function fillNcm( int $orderId, array $productIds = [] ) {
		$body = $productIds ? [ 'productIds' => array_values( array_map( 'intval', $productIds ) ) ] : [];

		return $this->data( $this->client->post( $this->orderPath( $orderId, 'nfe/fill-ncm' ), $body ) );
	}

	/**
	 * @param string $file FILE_DANFE, FILE_XML or FILE_CANCELLATION_XML.
	 * @param string $invoiceId Blank downloads the current invoice of the order (for the cancellation XML, its latest
	 *                          cancelled invoice).
	 *
	 * @return array{body: string, contentType: string, filename: string}|\WP_Error
	 */
	public function download( int $orderId, string $file, string $invoiceId = '' ) {
		if ( ! isset( self::FILE_ROUTES[ $file ] ) ) {
			return new \WP_Error( 'invalid_invoice_file', __( 'Unknown invoice file.', 'infixs-squidcore-erp' ), [ 'status' => 400 ] );
		}

		[ $path, $query ] = self::FILE_ROUTES[ $file ];

		if ( $invoiceId !== '' ) {
			$query['nfeId'] = $invoiceId;
		}

		return $this->client->download( $this->orderPath( $orderId, $path ), $query );
	}

	private function orderPath( int $orderId, string $path ): string {
		return self::BASE . '/orders/' . $orderId . '/' . $path;
	}

	/**
	 * @param array|\WP_Error $response
	 *
	 * @return array|\WP_Error
	 */
	private function data( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : [];
	}
}
