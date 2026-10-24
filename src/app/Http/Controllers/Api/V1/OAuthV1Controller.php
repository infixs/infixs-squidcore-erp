<?php

namespace Infixs\SquidCore\Http\Controllers\Api\V1;

use Infixs\SquidCore\AvelPress\Routing\Controller;
use Infixs\SquidCore\Services\OAuthConnectService;

defined( 'ABSPATH' ) || exit;

/**
 * "Conectar com SquidCore": starts the authorization the browser is sent to.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class OAuthV1Controller extends Controller {

	public function start() {
		$result = $this->oauth()->start( get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'data' => $result,
		];
	}

	/**
	 * Resolved with the plugin app id: with several AvelPress plugins active, constructor injection picks the wrong app.
	 */
	private function oauth(): OAuthConnectService {
		return \infixs_squidcore_app( OAuthConnectService::class );
	}
}
