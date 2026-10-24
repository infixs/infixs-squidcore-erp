<?php

namespace Infixs\SquidCore\Http\Controllers\Api\V1;

use Infixs\SquidCore\AvelPress\Routing\Controller;
use Infixs\SquidCore\AvelPress\Utils\Sanitizer;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin\InvoiceMetaBox;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceLabels;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoicePresenter;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRecord;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;

defined( 'ABSPATH' ) || exit;

/**
 * Invoice (NF-e) actions of one order, used by the order screen. Answers carry the invoice and `html`, the order
 * screen box rendered again.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class OrderInvoiceV1Controller extends Controller {

	public function show( \WP_REST_Request $request ) {
		$order = $this->order( $request );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return $this->respond( $order, $this->service()->find( $order ) );
	}

	public function emit( \WP_REST_Request $request ) {
		$order = $this->order( $request );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return $this->respond( $order, $this->service()->emit( $order, InvoiceService::TRIGGER_MANUAL ) );
	}

	public function refresh( \WP_REST_Request $request ) {
		$order = $this->order( $request );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return $this->respond( $order, $this->service()->refresh( $order ) );
	}

	public function cancel( \WP_REST_Request $request ) {
		$order = $this->order( $request );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : [];

		return $this->respond( $order, $this->service()->cancel( $order, Sanitizer::textarea( $body['justificativa'] ?? '' ) ) );
	}

	public function fillNcm( \WP_REST_Request $request ) {
		$order = $this->order( $request );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$body = $request->get_json_params();
		$product_ids = is_array( $body ) && isset( $body['productIds'] ) && is_array( $body['productIds'] )
			? array_values( array_filter( array_map( 'absint', $body['productIds'] ) ) )
			: [];

		$result = $this->service()->fillNcm( $order, $product_ids );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'data' => [
				'result' => $result,
				'invoice' => $this->presenter()->present( $order, $this->service()->find( $order ) ),
			],
			'html' => $this->metaBox()->html( $order, InvoiceLabels::ncmFillResult( $result ) ),
		];
	}

	/**
	 * @param \WC_Order $order
	 * @param InvoiceRecord|\WP_Error|null $record
	 *
	 * @return array|\WP_Error
	 */
	private function respond( \WC_Order $order, $record ) {
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		return [
			'data' => $this->presenter()->present( $order, $record ),
			'html' => $this->metaBox()->html( $order ),
		];
	}

	/**
	 * @return \WC_Order|\WP_Error
	 */
	private function order( \WP_REST_Request $request ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $request->get_param( 'id' ) ) ) : null;

		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'order_not_found', __( 'Order not found.', 'infixs-squidcore-erp' ), [ 'status' => 404 ] );
		}

		return $order;
	}

	/**
	 * Resolved with the plugin app id: with several AvelPress plugins active, constructor injection picks the wrong app.
	 */
	private function service(): InvoiceService {
		return \infixs_squidcore_app( InvoiceService::class );
	}

	private function presenter(): InvoicePresenter {
		return \infixs_squidcore_app( InvoicePresenter::class );
	}

	private function metaBox(): InvoiceMetaBox {
		return \infixs_squidcore_app( InvoiceMetaBox::class );
	}
}
