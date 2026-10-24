<?php

namespace Infixs\SquidCore\Support\OAuth;

use Infixs\SquidCore\Support\ApiErrorMessage;
use Infixs\SquidCore\Support\Credentials\CredentialProvider;
use Infixs\SquidCore\Support\Credentials\FixedCredential;
use Infixs\SquidCore\Support\SquidCoreClient;

defined( 'ABSPATH' ) || exit;

/**
 * Ends an OAuth connection of the store in SquidCore, with its own token: SquidCore revokes the authorization (consent,
 * tokens and codes, as revoking it in the panel does) and marks the plugin disconnected in the integration of that
 * organization.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class OAuthRevocation {

	private const DISCONNECT_ENDPOINT = 'integrations/apps/woocommerce/disconnect';

	/**
	 * @var \Closure(string, CredentialProvider): SquidCoreClient
	 */
	protected $makeClient;

	/**
	 * @param \Closure $makeClient Builds a client for the given API URL and credential.
	 */
	public function __construct( \Closure $makeClient ) {
		$this->makeClient = $makeClient;
	}

	/**
	 * @param string $apiUrl API that issued the token.
	 * @param string $accessToken
	 *
	 * @return \WP_Error|null Why SquidCore could not be told; null when it was, or had already revoked the token (401).
	 */
	public function end( string $apiUrl, string $accessToken ): ?\WP_Error {
		/** @var SquidCoreClient $client */
		$client = ( $this->makeClient )( $apiUrl, new FixedCredential( $accessToken, CredentialProvider::METHOD_OAUTH ) );
		$response = $client->post( self::DISCONNECT_ENDPOINT, [
			'siteUrl' => home_url(),
		] );

		return is_wp_error( $response ) && ApiErrorMessage::status( $response ) !== 401 ? $response : null;
	}
}
