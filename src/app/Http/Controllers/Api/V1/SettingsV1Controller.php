<?php

namespace Infixs\SquidCore\Http\Controllers\Api\V1;

use Infixs\SquidCore\AvelPress\Routing\Controller;
use Infixs\SquidCore\AvelPress\Utils\Sanitizer;
use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosAutomatico;
use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaBR;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceSettings;
use Infixs\SquidCore\Services\ConnectionService;
use Infixs\SquidCore\Services\OAuthConnectService;
use Infixs\SquidCore\Services\SyncService;

defined( 'ABSPATH' ) || exit;

/**
 * Connection credentials, sync and invoice options.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class SettingsV1Controller extends Controller {

	public function show() {
		return [
			'data' => [
				'connection' => $this->connection()->settings(),
				'oauth' => \infixs_squidcore_app( OAuthConnectService::class )->overview( get_current_user_id() ),
				'sync' => $this->sync()->options(),
				'invoices' => $this->invoices()->toArray(),
				'integrations' => [
					'correiosAutomatico' => \infixs_squidcore_app( CorreiosAutomatico::class )->toArray(),
					'webmaniaBR' => \infixs_squidcore_app( WebmaniaBR::class )->toArray(),
				],
			],
		];
	}

	public function updateConnection( \WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_payload', __( 'Invalid request payload.', 'infixs-squidcore-erp' ), [ 'status' => 400 ] );
		}

		$result = $this->connection()->save( Sanitizer::string( $body['token'] ?? '' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'data' => [
				'result' => $result,
				'settings' => $this->connection()->settings(),
			],
		];
	}

	public function updateSync( \WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_payload', __( 'Invalid request payload.', 'infixs-squidcore-erp' ), [ 'status' => 400 ] );
		}

		$this->sync()->saveOptions(
			Sanitizer::boolean( $body['catalog'] ?? true ),
			Sanitizer::boolean( $body['orders'] ?? true )
		);

		return [
			'data' => $this->sync()->options(),
		];
	}

	public function updateInvoices( \WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_payload', __( 'Invalid request payload.', 'infixs-squidcore-erp' ), [ 'status' => 400 ] );
		}

		$result = $this->invoices()->save( $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'data' => $this->invoices()->toArray(),
		];
	}

	/**
	 * Resolved with the plugin app id: with several AvelPress plugins active, constructor injection picks the wrong app.
	 */
	private function connection(): ConnectionService {
		return \infixs_squidcore_app( ConnectionService::class );
	}

	private function sync(): SyncService {
		return \infixs_squidcore_app( SyncService::class );
	}

	private function invoices(): InvoiceSettings {
		return \infixs_squidcore_app( InvoiceSettings::class );
	}
}
