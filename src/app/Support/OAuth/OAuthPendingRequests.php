<?php

namespace Infixs\SquidCore\Support\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Authorization requests on their way to SquidCore and back: the state and the PKCE verifier (RFC 7636, S256), kept
 * for ten minutes and for one use. The transient name carries the SHA-256 of the state, never the state itself.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class OAuthPendingRequests {

	private const PREFIX = 'infixs_squidcore_oauth_';

	private const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Opens a request.
	 *
	 * @param array $context What the return is checked against: user_id, redirect_uri, api_url and issuer.
	 *
	 * @return array{state: string, codeChallenge: string}|\WP_Error
	 */
	public function open( array $context ) {
		try {
			$state = $this->randomToken( 32 );
			$verifier = $this->randomToken( 48 );
		} catch ( \Exception $exception ) {
			return new \WP_Error( 'oauth_random_failed', __( 'Could not start the connection. Try again.', 'infixs-squidcore-erp' ), [ 'status' => 500 ] );
		}

		if ( ! set_transient( $this->key( $state ), array_merge( $context, [ 'verifier' => $verifier ] ), self::TTL ) ) {
			return new \WP_Error( 'oauth_state_failed', __( 'Could not start the connection. Try again.', 'infixs-squidcore-erp' ), [ 'status' => 500 ] );
		}

		return [
			'state' => $state,
			'codeChallenge' => $this->base64Url( hash( 'sha256', $verifier, true ) ),
		];
	}

	/**
	 * Takes the request of a state out, before anything else is done with the return: the code is exchanged at most
	 * once, even when the page is reloaded or the return arrives twice (SquidCore revokes the authorization of a
	 * reused code).
	 *
	 * @return array|null The context given to open() plus `verifier`; null when there is none.
	 */
	public function consume( string $state ): ?array {
		if ( $state === '' || strlen( $state ) > 512 ) {
			return null;
		}

		$key = $this->key( $state );
		$request = get_transient( $key );

		// Only the request that actually deleted it goes on.
		if ( ! delete_transient( $key ) ) {
			return null;
		}

		if ( ! is_array( $request ) || empty( $request['verifier'] ) || empty( $request['redirect_uri'] ) || ! isset( $request['user_id'], $request['api_url'], $request['issuer'] ) ) {
			return null;
		}

		return $request;
	}

	private function key( string $state ): string {
		return self::PREFIX . hash( 'sha256', $state );
	}

	/**
	 * Random base64url string (a verifier must have 43 to 128 characters).
	 *
	 * @throws \Exception When the system has no source of randomness.
	 */
	private function randomToken( int $bytes ): string {
		return $this->base64Url( random_bytes( $bytes ) );
	}

	private function base64Url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE and state encoding.
	}
}
