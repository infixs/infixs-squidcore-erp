<?php

namespace Infixs\SquidCore\Support\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Bearer credential of the SquidCore API, read again for every request: a connection made, replaced or revoked by
 * another request (the admin, a background job) applies to the next call without rebuilding the client.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
interface CredentialProvider {

	/**
	 * Access token of the "Conectar com SquidCore" authorization (OAuth).
	 */
	public const METHOD_OAUTH = 'oauth';

	/**
	 * Plugin token pasted in the manual connection.
	 */
	public const METHOD_TOKEN = 'token';

	/**
	 * Token for the next request; blank when there is none that may be sent.
	 */
	public function token(): string;

	/**
	 * How the store connects: one of the METHOD_* constants, blank when it does not.
	 */
	public function method(): string;

	/**
	 * SquidCore refused the token (401).
	 *
	 * @param string $token The token the refused request carried.
	 */
	public function rejected( string $token ): void;
}
