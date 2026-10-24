<?php

namespace Infixs\SquidCore\Http\Controllers\Api\V1;

use Infixs\SquidCore\AvelPress\Routing\Controller;
use Infixs\SquidCore\AvelPress\Utils\Sanitizer;
use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaNumberingCheck;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportItems;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportRun;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportService;

defined( 'ABSPATH' ) || exit;

/**
 * Import data tab: the import of the NCM codes of WebmaniaBR and the check of its NF-e numbering.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportV1Controller extends Controller {

	private const MAX_PER_PAGE = 100;

	public function show() {
		return [
			'data' => $this->service()->overview( get_current_user_id() ),
		];
	}

	public function scan( \WP_REST_Request $request ) {
		if ( $this->body( $request ) === null ) {
			return $this->invalidPayload();
		}

		$this->service()->scan();

		return new \WP_REST_Response( [ 'data' => [ 'scheduled' => true ] ], 202 );
	}

	public function preview( \WP_REST_Request $request ) {
		$body = $this->body( $request );

		if ( $body === null ) {
			return $this->invalidPayload();
		}

		return $this->respond( $this->service()->preview( $body, wp_get_current_user() ), 201 );
	}

	public function start( \WP_REST_Request $request ) {
		$run_id = $this->runId( $request );

		return $run_id === null ? $this->invalidPayload() : $this->respond( $this->service()->start( $run_id, wp_get_current_user() ), 201 );
	}

	public function step( \WP_REST_Request $request ) {
		$run_id = $this->runId( $request );

		return $run_id === null ? $this->invalidPayload() : $this->respond( $this->service()->step( $run_id ), 200 );
	}

	public function stop( \WP_REST_Request $request ) {
		$run_id = $this->runId( $request );

		return $run_id === null ? $this->invalidPayload() : $this->respond( $this->service()->stop( $run_id ), 200 );
	}

	public function resume( \WP_REST_Request $request ) {
		$run_id = $this->runId( $request );

		return $run_id === null ? $this->invalidPayload() : $this->respond( $this->service()->resume( $run_id ), 200 );
	}

	public function push( \WP_REST_Request $request ) {
		$body = $this->body( $request );

		if ( $body === null ) {
			return $this->invalidPayload();
		}

		$run_id = null;

		if ( isset( $body['runId'] ) ) {
			$run_id = $this->runId( $request );

			if ( $run_id === null ) {
				return $this->invalidPayload();
			}
		}

		return $this->respond( $this->service()->push( $run_id ), 200 );
	}

	public function undo( \WP_REST_Request $request ) {
		$run_id = $this->runId( $request );

		return $run_id === null ? $this->invalidPayload() : $this->respond( $this->service()->undo( $run_id, wp_get_current_user() ), 201 );
	}

	public function items( \WP_REST_Request $request ) {
		$run_id = Sanitizer::string( $request->get_param( 'id' ) );
		$type = Sanitizer::string( $request->get_param( 'type' ) );

		if ( ! FiscalImportRun::isValidId( $run_id ) || ! in_array( $type, FiscalImportItems::TYPES, true ) ) {
			return $this->invalidPayload();
		}

		// The app table names the page `p`, the key it also keeps in the admin URL, where `page` is the screen.
		$page = $request->get_param( 'p' ) ?? $request->get_param( 'page' );

		return $this->service()->items(
			$run_id,
			$type,
			max( 1, Sanitizer::integer( $page, 1 ) ),
			min( self::MAX_PER_PAGE, max( 1, Sanitizer::integer( $request->get_param( 'perPage' ), 20 ) ) )
		);
	}

	public function numbering( \WP_REST_Request $request ) {
		return [
			'data' => \infixs_squidcore_app( WebmaniaNumberingCheck::class )->check( Sanitizer::boolean( $request->get_param( 'refresh' ) ) ),
		];
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
	 * The `runId` of the body, null when missing or malformed.
	 */
	private function runId( \WP_REST_Request $request ): ?string {
		$body = $this->body( $request );
		$run_id = is_array( $body ) && isset( $body['runId'] ) && is_string( $body['runId'] ) ? $body['runId'] : '';

		return FiscalImportRun::isValidId( $run_id ) ? $run_id : null;
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
	private function service(): FiscalImportService {
		return \infixs_squidcore_app( FiscalImportService::class );
	}
}
