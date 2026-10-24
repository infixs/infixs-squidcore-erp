<?php

namespace Infixs\SquidCore\Http\Controllers\Api\V1;

use Infixs\SquidCore\AvelPress\Routing\Controller;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportNoticeBoard;

defined( 'ABSPATH' ) || exit;

/**
 * Dismissing a notice of the plugin, from the app or from the X of a WordPress notice.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class NoticesV1Controller extends Controller {

	public function dismiss( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$mode = is_array( $body ) && isset( $body['mode'] ) && is_string( $body['mode'] ) ? $body['mode'] : '';

		if ( ! in_array( $mode, FiscalImportNoticeBoard::MODES, true ) ) {
			return new \WP_Error( 'invalid_payload', __( 'Invalid request payload.', 'infixs-squidcore-erp' ), [ 'status' => 400 ] );
		}

		$id = (string) $request->get_param( 'id' );
		$until = $this->board()->dismiss( get_current_user_id(), $id, $mode );

		if ( $until === null ) {
			return new \WP_Error( 'invalid_notice', __( 'Unknown notice.', 'infixs-squidcore-erp' ), [ 'status' => 400 ] );
		}

		return [
			'data' => [
				'id' => $id,
				'until' => $until,
			],
		];
	}

	/**
	 * Resolved with the plugin app id: with several AvelPress plugins active, constructor injection picks the wrong app.
	 */
	private function board(): FiscalImportNoticeBoard {
		return \infixs_squidcore_app( FiscalImportNoticeBoard::class );
	}
}
