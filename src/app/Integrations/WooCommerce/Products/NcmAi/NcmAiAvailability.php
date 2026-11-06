<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

use Infixs\SquidCore\Services\ConnectionService;
use Infixs\SquidCore\Support\ApiErrorMessage;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the store can ask the AI of SquidCore for the NCM of its products, outside the invoice: the store is
 * connected, the organization has an AI (as of the last handshake) and the SquidCore version has the product NCM
 * routes. A version without them answers 404 without a code; that is remembered for a while (and forgotten when the
 * store points to another connection), so the button and the bulk filling go away instead of failing.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class NcmAiAvailability {

	public const UNSUPPORTED_TRANSIENT = 'infixs_squidcore_ncm_ai_unsupported';

	public const UNSUPPORTED_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	public function __construct( ConnectionService $connection ) {
		$this->connection = $connection;
	}

	public function register(): void {
		add_action( 'infixs_squidcore_connection_changed', [ $this, 'forgetUnsupported' ] );
	}

	public function isAvailable(): bool {
		return $this->connection->aiNcmAvailable() && ! $this->isUnsupported();
	}

	public function isConnected(): bool {
		return $this->connection->isConnected();
	}

	/**
	 * SquidCore answered, a moment ago, that it has no product NCM routes.
	 */
	public function isUnsupported(): bool {
		return get_transient( self::UNSUPPORTED_TRANSIENT ) !== false;
	}

	/**
	 * Remembers a SquidCore without the route when the error says so: a 404 without a code, whose message (if any) is
	 * the one of an unknown route ("Cannot POST …"). A 404 with a code (the product not synced yet) is an answer of
	 * the route itself.
	 *
	 * @return bool Whether the error was a missing route.
	 */
	public function noteRouteMissing( \WP_Error $error ): bool {
		if ( ! self::isRouteMissing( $error ) ) {
			return false;
		}

		set_transient( self::UNSUPPORTED_TRANSIENT, '1', self::UNSUPPORTED_TTL );

		return true;
	}

	public function forgetUnsupported(): void {
		delete_transient( self::UNSUPPORTED_TRANSIENT );
	}

	public static function isRouteMissing( \WP_Error $error ): bool {
		if ( ApiErrorMessage::status( $error ) !== 404 || ApiErrorMessage::code( $error ) !== '' ) {
			return false;
		}

		$message = ApiErrorMessage::body( $error )['message'] ?? '';

		return ! is_string( $message ) || $message === '' || strpos( $message, 'Cannot ' ) === 0;
	}
}
