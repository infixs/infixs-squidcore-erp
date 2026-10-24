<?php

namespace Infixs\SquidCore\Services;

use Infixs\SquidCore\Integrations\WooCommerce\WooCommerceApiKeys;
use Infixs\SquidCore\Support\ApiErrorMessage;
use Infixs\SquidCore\Support\Credentials\CredentialProvider;
use Infixs\SquidCore\Support\Credentials\FixedCredential;
use Infixs\SquidCore\Support\OAuth\OAuthPendingRequests;
use Infixs\SquidCore\Support\OAuth\OAuthRevocation;
use Infixs\SquidCore\Support\OAuth\OAuthServer;
use Infixs\SquidCore\Support\OAuth\OAuthTokenStore;
use Infixs\SquidCore\Support\SquidCoreClient;
use Infixs\SquidCore\Support\SquidCoreUrls;

defined( 'ABSPATH' ) || exit;

/**
 * "Conectar com SquidCore": the store owner authorizes the store in SquidCore (OAuth 2.1 authorization code with
 * PKCE, as the official public client "SquidCore para WooCommerce") and the plugin creates the WooCommerce REST API
 * keys SquidCore needs and hands them over, so no token is copied by hand.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class OAuthConnectService {

	/**
	 * admin-post action SquidCore sends the browser back to.
	 */
	public const CALLBACK_ACTION = 'infixs_squidcore_oauth_callback';

	public const RESULT_CONNECTED = 'connected';

	/**
	 * Authorized and handed over, but the check after it failed.
	 */
	public const RESULT_UNCONFIRMED = 'unconfirmed';

	public const RESULT_DENIED = 'denied';

	/**
	 * The return matched no pending request: expired, already used or never started here.
	 */
	public const RESULT_EXPIRED = 'expired';

	public const RESULT_FAILED = 'failed';

	private const CONNECT_ENDPOINT = 'integrations/apps/woocommerce/connect';

	/**
	 * Outcome of the last return of each user, shown by the settings screen the browser lands on.
	 */
	private const RESULT_PREFIX = 'infixs_squidcore_oauth_result_';

	private const RESULT_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Longest store name SquidCore takes.
	 */
	private const STORE_NAME_MAX_LENGTH = 100;

	/**
	 * @var SquidCoreUrls
	 */
	protected $urls;

	/**
	 * @var OAuthServer
	 */
	protected $server;

	/**
	 * @var OAuthPendingRequests
	 */
	protected $pending;

	/**
	 * @var OAuthTokenStore
	 */
	protected $store;

	/**
	 * @var WooCommerceApiKeys
	 */
	protected $apiKeys;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	/**
	 * @var OAuthRevocation
	 */
	protected $revocation;

	/**
	 * @var \Closure(string, CredentialProvider): SquidCoreClient
	 */
	protected $makeClient;

	/**
	 * @var string
	 */
	protected $scopes;

	/**
	 * @var string
	 */
	protected $pluginVersion;

	/**
	 * @param SquidCoreUrls $urls
	 * @param OAuthServer $server
	 * @param OAuthPendingRequests $pending
	 * @param OAuthTokenStore $store
	 * @param WooCommerceApiKeys $apiKeys
	 * @param ConnectionService $connection Checks the new connection, as saving a token does.
	 * @param OAuthRevocation $revocation Ends a replaced connection in SquidCore.
	 * @param \Closure $makeClient Builds a client for the given API URL and credential.
	 * @param string $scopes Scopes requested, space separated.
	 * @param string $pluginVersion
	 */
	public function __construct(
		SquidCoreUrls $urls,
		OAuthServer $server,
		OAuthPendingRequests $pending,
		OAuthTokenStore $store,
		WooCommerceApiKeys $apiKeys,
		ConnectionService $connection,
		OAuthRevocation $revocation,
		\Closure $makeClient,
		string $scopes,
		string $pluginVersion
	) {
		$this->urls = $urls;
		$this->server = $server;
		$this->pending = $pending;
		$this->store = $store;
		$this->apiKeys = $apiKeys;
		$this->connection = $connection;
		$this->revocation = $revocation;
		$this->makeClient = $makeClient;
		$this->scopes = $scopes;
		$this->pluginVersion = $pluginVersion;
	}

	/**
	 * What the settings screen needs: whether the button works here and the outcome of the user's last return.
	 *
	 * @return array{available: bool, reason: string|null, lastResult: array|null}
	 */
	public function overview( int $userId ): array {
		return array_merge( $this->availability(), [
			'lastResult' => $this->lastResult( $userId ),
		] );
	}

	/**
	 * Whether the button can work here, and why not, judged from this site alone: SquidCore is only asked (for its
	 * OAuth metadata) when the user clicks the button, so opening the plugin screens sends nothing to it.
	 *
	 * @return array{available: bool, reason: string|null}
	 */
	public function availability(): array {
		$problem = $this->localProblem();

		return [
			'available' => $problem === null,
			'reason' => $problem ? $problem->get_error_message() : null,
		];
	}

	/**
	 * Starts the authorization: the state and the PKCE verifier are kept for ten minutes, for one use.
	 *
	 * @param int $userId WordPress user who starts it; only they can finish it.
	 *
	 * @return array{authorizeUrl: string}|\WP_Error
	 */
	public function start( int $userId ) {
		$problem = $this->localProblem() ?? $this->serverProblem();

		if ( $problem ) {
			$problem->add_data( [ 'status' => 400 ] );

			return $problem;
		}

		$redirect_uri = $this->redirectUri();
		$request = $this->pending->open( [
			'user_id' => $userId,
			'redirect_uri' => $redirect_uri,
			'api_url' => $this->urls->apiUrl(),
			'issuer' => $this->server->issuer(),
			'iss_required' => $this->server->sendsIssuerInResponse(),
		] );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		return [
			'authorizeUrl' => $this->server->authorizeUrl( [
				'response_type' => 'code',
				'redirect_uri' => $redirect_uri,
				'scope' => $this->scopes,
				'state' => $request['state'],
				'code_challenge' => $request['codeChallenge'],
				'code_challenge_method' => 'S256',
				// Always the consent screen: a silent approval would bind this store to the organization of a previous one.
				'prompt' => 'consent',
			] ),
		];
	}

	/**
	 * Finishes the authorization SquidCore sent the browser back with.
	 *
	 * @param array $query `state`, `code`, `error` and `iss` of the return.
	 * @param int $userId WordPress user the browser is logged in as.
	 *
	 * @return string One of the RESULT_* constants; the message is kept for lastResult().
	 */
	public function complete( array $query, int $userId ): string {
		$value = function ( string $name ) use ( $query ): string {
			return isset( $query[ $name ] ) && is_string( $query[ $name ] ) ? trim( $query[ $name ] ) : '';
		};

		$request = $this->pending->consume( $value( 'state' ) );

		if ( ! $request ) {
			return $this->finish( $userId, self::RESULT_EXPIRED, __( 'The connection request expired or was already used. Click Connect with SquidCore again.', 'infixs-squidcore-erp' ) );
		}

		if ( (int) $request['user_id'] !== $userId ) {
			return $this->finish( $userId, self::RESULT_FAILED, __( 'The connection was started by another WordPress user. Start it again with your own user.', 'infixs-squidcore-erp' ) );
		}

		// RFC 9207: the answer, success or error, must come from the server the request went to, and carries its issuer
		// whenever the metadata promises it.
		$issuer = $value( 'iss' );
		$issuer_missing = $issuer === '' && ! empty( $request['iss_required'] );

		if ( $issuer_missing || ( $issuer !== '' && untrailingslashit( $issuer ) !== (string) $request['issuer'] ) ) {
			// SquidCore may have changed its issuer since the metadata was cached: the next attempt reads it again.
			$this->server->forgetMetadata();

			return $this->finish( $userId, self::RESULT_FAILED, __( 'The answer did not come from the SquidCore server the connection was started with. Click Connect with SquidCore again.', 'infixs-squidcore-erp' ) );
		}

		$error = $value( 'error' );

		if ( $error === 'access_denied' ) {
			return $this->finish( $userId, self::RESULT_DENIED, __( 'The authorization was cancelled in SquidCore, so the store was not connected.', 'infixs-squidcore-erp' ) );
		}

		if ( $error !== '' ) {
			/* translators: %s: OAuth error code returned by SquidCore, e.g. "server_error". */
			return $this->finish( $userId, self::RESULT_FAILED, sprintf( __( 'SquidCore did not authorize the connection (%s). Click Connect with SquidCore again.', 'infixs-squidcore-erp' ), sanitize_key( $error ) ) );
		}

		if ( (string) $request['api_url'] !== $this->urls->apiUrl() ) {
			return $this->finish( $userId, self::RESULT_FAILED, __( 'The SquidCore API address changed during the connection. Click Connect with SquidCore again.', 'infixs-squidcore-erp' ) );
		}

		$code = $value( 'code' );

		if ( $code === '' ) {
			return $this->finish( $userId, self::RESULT_FAILED, __( 'SquidCore sent the browser back without an authorization code. Click Connect with SquidCore again.', 'infixs-squidcore-erp' ) );
		}

		$token = $this->server->exchangeCode( $code, (string) $request['redirect_uri'], (string) $request['verifier'] );

		if ( is_wp_error( $token ) ) {
			return $this->finish( $userId, self::RESULT_FAILED, sprintf(
				/* translators: %s: why the token request failed. */
				__( 'SquidCore did not issue the access token (%s). Click Connect with SquidCore again.', 'infixs-squidcore-erp' ),
				$this->tokenErrorDetail( $token )
			) );
		}

		return $this->handOver( $token, $request, $userId );
	}

	/**
	 * Outcome of the last return of a user, while it is fresh.
	 *
	 * @return array{code: string, message: string, at: string}|null
	 */
	public function lastResult( int $userId ): ?array {
		$result = get_transient( self::RESULT_PREFIX . $userId );

		return is_array( $result ) && isset( $result['code'], $result['message'] ) ? $result : null;
	}

	/**
	 * Where SquidCore sends the browser back. SquidCore only accepts https (http only on loopback); a site behind a
	 * proxy that hides https still announces the https address it is reached at.
	 */
	public function redirectUri(): string {
		$scheme = is_ssl() || force_ssl_admin() || 'https' === wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_SCHEME ) ? 'https' : 'http';

		return admin_url( 'admin-post.php?action=' . self::CALLBACK_ACTION, $scheme );
	}

	/**
	 * Creates the WooCommerce key, hands it to SquidCore and stores the connection. Anything created is undone when
	 * SquidCore refuses it.
	 *
	 * @param array $token Answer of the token endpoint.
	 * @param array $request The pending request of the state.
	 */
	private function handOver( array $token, array $request, int $userId ): string {
		$access_token = (string) $token['access_token'];
		$key = $this->apiKeys->create( $userId );

		if ( is_wp_error( $key ) ) {
			$this->server->revoke( $access_token );

			return $this->finish( $userId, self::RESULT_FAILED, $key->get_error_message() );
		}

		/** @var SquidCoreClient $client */
		$client = ( $this->makeClient )( $this->urls->apiUrl(), new FixedCredential( $access_token, CredentialProvider::METHOD_OAUTH ) );
		$response = $client->post( self::CONNECT_ENDPOINT, [
			'siteUrl' => home_url(),
			'consumerKey' => $key['consumerKey'],
			'consumerSecret' => $key['consumerSecret'],
			'pluginVersion' => $this->pluginVersion,
			'storeName' => $this->storeName(),
		] );

		$answer = is_wp_error( $response ) ? [] : $this->unwrap( $response );

		if ( empty( $answer['connected'] ) ) {
			$this->apiKeys->delete( $key['id'] );
			$this->server->revoke( $access_token );

			return $this->finish( $userId, self::RESULT_FAILED, is_wp_error( $response )
				? $this->connectErrorMessage( $response )
				: __( 'SquidCore did not confirm the connection of the store. Click Connect with SquidCore again.', 'infixs-squidcore-erp' ) );
		}

		$previous = $this->store->get();
		$organization_id = isset( $answer['organization']['id'] ) && is_string( $answer['organization']['id'] ) ? sanitize_text_field( $answer['organization']['id'] ) : '';

		$this->store->save( [
			'access_token' => $access_token,
			'scope' => isset( $token['scope'] ) && is_string( $token['scope'] ) ? $token['scope'] : $this->scopes,
			'client_id' => $this->server->clientId(),
			'issuer' => (string) $request['issuer'],
			'api_url' => $this->urls->apiUrl(),
			'connected_at' => gmdate( 'c' ),
			'wc_key_id' => $key['id'],
			'organization_id' => $organization_id,
		] );

		if ( $previous ) {
			$this->forgetPrevious( $previous, $key['id'], $access_token, $organization_id );
		}

		$check = $this->connection->useOAuth();

		if ( ! $check['connected'] ) {
			/* translators: %s: why the check failed. */
			return $this->finish( $userId, self::RESULT_UNCONFIRMED, sprintf( __( 'The store was authorized in SquidCore, but the connection check failed: %s', 'infixs-squidcore-erp' ), $check['message'] ) );
		}

		$organization = $check['organization']['name'] ?? ( $answer['organization']['name'] ?? '' );

		return $this->finish( $userId, self::RESULT_CONNECTED, is_string( $organization ) && $organization !== ''
			/* translators: %s: name of the SquidCore organization. */
			? sprintf( __( 'Store connected to SquidCore: %s.', 'infixs-squidcore-erp' ), sanitize_text_field( $organization ) )
			: __( 'Store connected to SquidCore.', 'infixs-squidcore-erp' ) );
	}

	/**
	 * A previous OAuth connection of the store (refused by SquidCore, or of another organization) is replaced: its key
	 * and token are no longer used.
	 *
	 * @param array $previous The replaced connection.
	 * @param int $keyId WooCommerce key just handed over.
	 * @param string $accessToken Token just issued.
	 * @param string $organizationId Organization the store was just connected to, blank when SquidCore did not tell.
	 */
	private function forgetPrevious( array $previous, int $keyId, string $accessToken, string $organizationId ): void {
		$previous_key = (int) ( $previous['wc_key_id'] ?? 0 );

		if ( $previous_key !== $keyId ) {
			$this->apiKeys->delete( $previous_key );
		}

		$previous_token = (string) $previous['access_token'];

		// A token of another API is never sent to this one.
		if ( $previous_token === $accessToken || (string) ( $previous['api_url'] ?? '' ) !== $this->urls->apiUrl() ) {
			return;
		}

		/*
		 * Moved to another organization: revoking the token alone would leave that organization's integration showing
		 * the store as connected, with a removed key, and the authorization among its connected apps. Ending the
		 * authorization in SquidCore clears both; for the same organization it would also end the new one.
		 */
		$previous_organization = (string) ( $previous['organization_id'] ?? '' );
		$other_organization = $previous_organization !== '' && $organizationId !== '' && $previous_organization !== $organizationId;

		if ( $other_organization && empty( $previous['needs_reconnect'] ) && ! $this->revocation->end( $this->urls->apiUrl(), $previous_token ) ) {
			return;
		}

		$this->server->revoke( $previous_token );
	}

	/**
	 * Name of the store for the integration SquidCore creates. SquidCore takes up to 100 characters as JavaScript
	 * counts them (a character outside the Basic Multilingual Plane, such as an emoji, counts twice); blank lets it
	 * name the integration after the host.
	 */
	private function storeName(): string {
		$name = mb_substr( trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ), 0, self::STORE_NAME_MAX_LENGTH );

		while ( $name !== '' && mb_strlen( $name ) + (int) preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $name ) > self::STORE_NAME_MAX_LENGTH ) {
			$name = mb_substr( $name, 0, -1 );
		}

		return trim( $name );
	}

	/**
	 * Why the button cannot be used on this site, null when it can. Asks SquidCore nothing.
	 */
	private function localProblem(): ?\WP_Error {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new \WP_Error( 'woocommerce_inactive', __( 'Connect with SquidCore needs WooCommerce active: it creates the WooCommerce REST API keys SquidCore uses.', 'infixs-squidcore-erp' ) );
		}

		$invalid_url = $this->urls->apiUrlError();

		if ( $invalid_url ) {
			return $invalid_url;
		}

		if ( ! $this->server->acceptsRedirectUri( $this->redirectUri() ) ) {
			return new \WP_Error( 'insecure_redirect_uri', sprintf(
				/* translators: %s: address of the WordPress admin. */
				__( 'SquidCore only sends the browser back to a WordPress admin served over https:// (http:// only on localhost), and this site answers at %s. Turn on https or use the manual connection with a token.', 'infixs-squidcore-erp' ),
				esc_url_raw( admin_url() )
			) );
		}

		return null;
	}

	/**
	 * Why SquidCore cannot take the authorization, null when it can: reads its OAuth metadata (cached), so it only
	 * runs once the user clicked the button.
	 */
	private function serverProblem(): ?\WP_Error {
		if ( $this->server->issuer() === '' ) {
			return new \WP_Error( 'oauth_unavailable', __( 'SquidCore does not offer Connect with SquidCore at this address yet. Use the manual connection with a token.', 'infixs-squidcore-erp' ) );
		}

		return null;
	}

	private function finish( int $userId, string $result, string $message ): string {
		set_transient( self::RESULT_PREFIX . $userId, [
			'code' => $result,
			'message' => $message,
			'at' => gmdate( 'c' ),
		], self::RESULT_TTL );

		return $result;
	}

	/**
	 * Message for a refused hand-over of the store.
	 */
	private function connectErrorMessage( \WP_Error $error ): string {
		$status = ApiErrorMessage::status( $error );
		$code = ApiErrorMessage::code( $error );
		$api_message = ApiErrorMessage::body( $error )['message'] ?? '';

		if ( $code === 'WOO_INTEGRATION_OTHER_STORE' ) {
			return is_string( $api_message ) && trim( $api_message ) !== ''
				? sanitize_text_field( $api_message )
				: __( 'This SquidCore company is already connected to another store. Disconnect that store in SquidCore, or choose another company.', 'infixs-squidcore-erp' );
		}

		if ( $status === 402 ) {
			return __( 'Your SquidCore plan does not include integrations. Upgrade your plan to connect the store.', 'infixs-squidcore-erp' );
		}

		// A SquidCore version without the hand-over route.
		if ( $status === 404 && $code === '' ) {
			return __( 'This SquidCore version cannot connect stores with Connect with SquidCore yet. Use the manual connection with a token.', 'infixs-squidcore-erp' );
		}

		return ApiErrorMessage::describe( $error );
	}

	/**
	 * Detail of a failed token request: the OAuth error code, or why the API could not be reached.
	 */
	private function tokenErrorDetail( \WP_Error $error ): string {
		$oauth_error = ApiErrorMessage::body( $error )['error'] ?? '';

		if ( is_string( $oauth_error ) && $oauth_error !== '' ) {
			return sanitize_key( $oauth_error );
		}

		$status = ApiErrorMessage::status( $error );

		return $status > 0 ? 'HTTP ' . $status : ApiErrorMessage::describe( $error );
	}

	/**
	 * The answer may come inside `data`, as the other WooCommerce routes answer.
	 */
	private function unwrap( array $response ): array {
		return isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;
	}
}
