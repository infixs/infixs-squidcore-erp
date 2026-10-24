<?php

namespace Infixs\SquidCore\Support\OAuth;

use Infixs\SquidCore\Support\SquidCoreUrls;

defined( 'ABSPATH' ) || exit;

/**
 * The SquidCore OAuth 2.1 authorization server, as the plugin uses it: a public client with PKCE.
 *
 * Every address is built from the configured API URL. The metadata only tells whether the API offers OAuth and which
 * issuer signs the answers; the endpoints it announces are never followed (a misconfigured server may announce
 * plain http ones, and the code and the verifier would travel unencrypted).
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class OAuthServer {

	public const CLIENT_ID_CONSTANT = 'INFIXS_SQUIDCORE_OAUTH_CLIENT_ID';

	private const METADATA_PATH = '/.well-known/oauth-authorization-server';

	private const METADATA_CACHE_PREFIX = 'infixs_squidcore_oauth_metadata_';

	private const METADATA_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * A definitive answer without OAuth (production before it) is asked again sooner, so the button shows up soon
	 * after SquidCore starts offering it.
	 */
	private const MISSING_METADATA_TTL = HOUR_IN_SECONDS;

	/**
	 * The API could not be reached: asked again in a few minutes.
	 */
	private const UNREACHABLE_METADATA_TTL = 5 * MINUTE_IN_SECONDS;

	private const METADATA_TIMEOUT = 5;

	private const TOKEN_TIMEOUT = 15;

	/**
	 * @var SquidCoreUrls
	 */
	protected $urls;

	/**
	 * @var string
	 */
	protected $clientId;

	/**
	 * @param SquidCoreUrls $urls
	 * @param string $defaultClientId The official client, used unless INFIXS_SQUIDCORE_OAUTH_CLIENT_ID is set.
	 */
	public function __construct( SquidCoreUrls $urls, string $defaultClientId ) {
		$this->urls = $urls;

		$override = defined( self::CLIENT_ID_CONSTANT ) ? constant( self::CLIENT_ID_CONSTANT ) : '';
		$this->clientId = is_string( $override ) && trim( $override ) !== '' ? trim( $override ) : $defaultClientId;
	}

	public function clientId(): string {
		return $this->clientId;
	}

	/**
	 * Issuer the API announces in its metadata (RFC 8414), blank when it offers no OAuth.
	 */
	public function issuer(): string {
		return $this->metadata()['issuer'];
	}

	/**
	 * Whether the API promises the issuer in every authorization answer (RFC 9207): an answer without it is then refused.
	 */
	public function sendsIssuerInResponse(): bool {
		return $this->metadata()['iss_in_response'];
	}

	/**
	 * Forgets the cached metadata, so the next issuer() asks the API again.
	 */
	public function forgetMetadata(): void {
		delete_transient( self::METADATA_CACHE_PREFIX . md5( $this->urls->apiUrl() ) );
	}

	/**
	 * Address of the authorization request.
	 *
	 * @param array $params Query arguments besides the client id.
	 */
	public function authorizeUrl( array $params ): string {
		return $this->endpoint( 'authorize' ) . '?' . http_build_query( array_merge( [ 'client_id' => $this->clientId ], $params ), '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchanges the authorization code for the access token (public client: the verifier proves who asked).
	 *
	 * @return array|\WP_Error The token answer, with a non-empty `access_token`.
	 */
	public function exchangeCode( string $code, string $redirectUri, string $verifier ) {
		$response = wp_remote_post( $this->endpoint( 'token' ), [
			'timeout' => self::TOKEN_TIMEOUT,
			'headers' => [ 'Accept' => 'application/json' ],
			'body' => [
				'grant_type' => 'authorization_code',
				'code' => $code,
				'redirect_uri' => $redirectUri,
				'client_id' => $this->clientId,
				'code_verifier' => $verifier,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : [];

		if ( $status !== 200 || empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			return new \WP_Error( 'oauth_token_failed', __( 'SquidCore did not issue the access token.', 'infixs-squidcore-erp' ), [
				'status' => $status,
				'body' => $data,
			] );
		}

		if ( isset( $data['token_type'] ) && strtolower( (string) $data['token_type'] ) !== 'bearer' ) {
			return new \WP_Error( 'oauth_token_failed', __( 'SquidCore issued a kind of token the plugin does not use.', 'infixs-squidcore-erp' ), [
				'status' => $status,
			] );
		}

		return $data;
	}

	/**
	 * The return address policy of SquidCore for the plugin: https, or http on loopback for development.
	 */
	public function acceptsRedirectUri( string $uri ): bool {
		$scheme = strtolower( (string) wp_parse_url( $uri, PHP_URL_SCHEME ) );
		$host = strtolower( trim( (string) wp_parse_url( $uri, PHP_URL_HOST ), '[]' ) );

		if ( $scheme === 'https' ) {
			return $host !== '';
		}

		if ( $scheme !== 'http' ) {
			return false;
		}

		return $host === 'localhost'
			|| $host === '::1'
			|| ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) !== false && strpos( $host, '127.' ) === 0 );
	}

	/**
	 * Revokes a token the plugin will not keep (best effort: the server always answers 200).
	 */
	public function revoke( string $token ): void {
		wp_remote_post( $this->endpoint( 'revoke' ), [
			'timeout' => self::METADATA_TIMEOUT,
			'headers' => [ 'Accept' => 'application/json' ],
			'body' => [
				'token' => $token,
				'token_type_hint' => 'access_token',
				'client_id' => $this->clientId,
			],
		] );
	}

	/**
	 * What the plugin reads from the metadata, cached.
	 *
	 * @return array{issuer: string, iss_in_response: bool}
	 */
	private function metadata(): array {
		$cache_key = self::METADATA_CACHE_PREFIX . md5( $this->urls->apiUrl() );
		$cached = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['issuer'] ) && is_string( $cached['issuer'] ) ) {
			return [
				'issuer' => $cached['issuer'],
				'iss_in_response' => ! empty( $cached['iss_in_response'] ),
			];
		}

		$response = wp_remote_get( $this->urls->apiUrl() . self::METADATA_PATH, [
			'timeout' => self::METADATA_TIMEOUT,
			'headers' => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) ) {
			$metadata = [
				'issuer' => '',
				'iss_in_response' => false,
			];
			set_transient( $cache_key, $metadata, self::UNREACHABLE_METADATA_TTL );

			return $metadata;
		}

		$metadata = $this->metadataOf( $response );
		set_transient( $cache_key, $metadata, $metadata['issuer'] !== '' ? self::METADATA_TTL : self::MISSING_METADATA_TTL );

		return $metadata;
	}

	/**
	 * @param array $response wp_remote_* response.
	 *
	 * @return array{issuer: string, iss_in_response: bool} A blank issuer when the API offers no OAuth the plugin can use.
	 */
	private function metadataOf( $response ): array {
		$none = [
			'issuer' => '',
			'iss_in_response' => false,
		];

		if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return $none;
		}

		$metadata = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $metadata ) || empty( $metadata['issuer'] ) || ! is_string( $metadata['issuer'] ) ) {
			return $none;
		}

		// Only S256 PKCE is used: a server without it cannot take the plugin, and one that does not list the methods
		// offers no PKCE at all (RFC 8414).
		$methods = $metadata['code_challenge_methods_supported'] ?? null;

		if ( ! is_array( $methods ) || ! in_array( 'S256', $methods, true ) ) {
			return $none;
		}

		return [
			'issuer' => untrailingslashit( trim( $metadata['issuer'] ) ),
			'iss_in_response' => true === ( $metadata['authorization_response_iss_parameter_supported'] ?? false ),
		];
	}

	private function endpoint( string $name ): string {
		return $this->urls->apiUrl() . '/v1/oauth/' . $name;
	}
}
