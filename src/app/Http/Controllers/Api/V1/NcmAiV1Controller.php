<?php

namespace Infixs\SquidCore\Http\Controllers\Api\V1;

use Infixs\SquidCore\AvelPress\Routing\Controller;
use Infixs\SquidCore\AvelPress\Utils\Sanitizer;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi\NcmAiErrors;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi\NcmAiFillRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi\NcmAiFillRun;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi\NcmAiFillService;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi\ProductNcmSuggestion;

defined( 'ABSPATH' ) || exit;

/**
 * The NCM by the AI of SquidCore outside the invoice: the suggestion of the product editor and the bulk filling of the
 * products without NCM (its confirmation, start, stop, resume and lists).
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class NcmAiV1Controller extends Controller {

	private const MAX_PER_PAGE = 100;

	public function suggest( \WP_REST_Request $request ) {
		$product_id = absint( $request->get_param( 'id' ) );
		$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof \WC_Product ) {
			return NcmAiErrors::make( NcmAiErrors::PRODUCT_NOT_FOUND, 404 );
		}

		if ( ! current_user_can( 'edit_product', $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id() ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit this product.', 'infixs-squidcore-erp' ), [ 'status' => rest_authorization_required_code() ] );
		}

		$suggestion = \infixs_squidcore_app( ProductNcmSuggestion::class )->suggest( $product );

		return is_wp_error( $suggestion ) ? $suggestion : [ 'data' => $suggestion ];
	}

	/**
	 * How many products the AI would be asked for: among the `wcProductIds` chosen (the products list), or among every
	 * product with `scope: "all"` (the settings), counted now rather than read from the last count.
	 */
	public function preview( \WP_REST_Request $request ) {
		$body = $this->body( $request );

		if ( is_array( $body ) && ( $body['scope'] ?? null ) === NcmAiFillRun::SCOPE_ALL ) {
			return [ 'data' => $this->service()->preview( null ) ];
		}

		$ids = $this->productIds( $request );

		return $ids === null ? $this->invalidPayload() : [ 'data' => $this->service()->preview( $ids ) ];
	}

	public function start( \WP_REST_Request $request ) {
		if ( $this->body( $request ) === null ) {
			return $this->invalidPayload();
		}

		return $this->respond( $this->service()->start( null, wp_get_current_user() ), 201 );
	}

	public function stop( \WP_REST_Request $request ) {
		$run_id = $this->runId( $request );

		return $run_id === null ? $this->invalidPayload() : $this->respond( $this->service()->stop( $run_id ), 200 );
	}

	public function resume( \WP_REST_Request $request ) {
		$run_id = $this->runId( $request );

		return $run_id === null ? $this->invalidPayload() : $this->respond( $this->service()->resume( $run_id ), 200 );
	}

	public function items( \WP_REST_Request $request ) {
		$run_id = Sanitizer::string( $request->get_param( 'id' ) );
		$list = Sanitizer::string( $request->get_param( 'list' ) );

		if ( ! NcmAiFillRun::isValidId( $run_id ) || ! in_array( $list, NcmAiFillRepository::LISTS, true ) ) {
			return $this->invalidPayload();
		}

		// The app table names the page `p`, the key it also keeps in the admin URL, where `page` is the screen.
		$page = $request->get_param( 'p' ) ?? $request->get_param( 'page' );

		return $this->service()->items(
			$run_id,
			$list,
			max( 1, Sanitizer::integer( $page, 1 ) ),
			min( self::MAX_PER_PAGE, max( 1, Sanitizer::integer( $request->get_param( 'perPage' ), 20 ) ) )
		);
	}

	/**
	 * The JSON object of the body ([] for an empty body), null when the body is something else.
	 */
	private function body( \WP_REST_Request $request ): ?array {
		if ( trim( (string) $request->get_body() ) === '' ) {
			return [];
		}

		$body = $request->get_json_params();

		return is_array( $body ) && ( $body === [] || ! wp_is_numeric_array( $body ) ) ? $body : null;
	}

	/**
	 * The `wcProductIds` of the body (a list of integers), null when missing or malformed.
	 *
	 * @return int[]|null
	 */
	private function productIds( \WP_REST_Request $request ): ?array {
		$body = $this->body( $request );
		$ids = is_array( $body ) ? ( $body['wcProductIds'] ?? null ) : null;

		if ( ! is_array( $ids ) || ! wp_is_numeric_array( $ids ) ) {
			return null;
		}

		return array_values( array_filter( array_map( 'absint', array_filter( $ids, 'is_numeric' ) ) ) );
	}

	/**
	 * The `runId` of the body, null when missing or malformed.
	 */
	private function runId( \WP_REST_Request $request ): ?string {
		$body = $this->body( $request );
		$run_id = is_array( $body ) && isset( $body['runId'] ) && is_string( $body['runId'] ) ? $body['runId'] : '';

		return NcmAiFillRun::isValidId( $run_id ) ? $run_id : null;
	}

	/**
	 * @param array|\WP_Error $result
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function respond( $result, int $status ) {
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( [ 'data' => $result ], $status );
	}

	private function invalidPayload(): \WP_Error {
		return new \WP_Error( 'invalid_payload', __( 'Invalid request payload.', 'infixs-squidcore-erp' ), [ 'status' => 400 ] );
	}

	/**
	 * Resolved with the plugin app id: with several AvelPress plugins active, constructor injection picks the wrong app.
	 */
	private function service(): NcmAiFillService {
		return \infixs_squidcore_app( NcmAiFillService::class );
	}
}
