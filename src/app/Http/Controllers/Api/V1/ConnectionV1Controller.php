<?php

namespace Infixs\SquidCore\Http\Controllers\Api\V1;

use Infixs\SquidCore\AvelPress\Routing\Controller;
use Infixs\SquidCore\AvelPress\Utils\Sanitizer;
use Infixs\SquidCore\Services\ConnectionService;

defined( 'ABSPATH' ) || exit;

/**
 * Connection actions: test the credentials and disconnect.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class ConnectionV1Controller extends Controller {

	public function test( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : [];

		$result = $this->connection()->test( Sanitizer::string( $body['token'] ?? '' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'data' => $result,
		];
	}

	/**
	 * Called by the panel while it stays open. The answer is what the store knows now.
	 */
	public function verify() {
		$this->connection()->verify();

		return [
			'data' => $this->connection()->status(),
		];
	}

	public function disconnect() {
		$warning = $this->connection()->disconnect();

		return [
			'data' => [
				'status' => $this->connection()->status(),
				'settings' => $this->connection()->settings(),
				'warning' => $warning,
			],
		];
	}

	private function connection(): ConnectionService {
		return \infixs_squidcore_app( ConnectionService::class );
	}
}
