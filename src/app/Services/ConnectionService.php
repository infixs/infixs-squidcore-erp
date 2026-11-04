<?php

namespace Infixs\SquidCore\Services;

use Infixs\SquidCore\AvelPress\Config\SettingsRepository;
use Infixs\SquidCore\Integrations\WooCommerce\ActivityRepository;
use Infixs\SquidCore\Integrations\WooCommerce\SyncStatusRepository;
use Infixs\SquidCore\Integrations\WooCommerce\WooCommerceApiKeys;
use Infixs\SquidCore\Support\ApiErrorMessage;
use Infixs\SquidCore\Support\Credentials\CredentialProvider;
use Infixs\SquidCore\Support\Credentials\FixedCredential;
use Infixs\SquidCore\Support\Credentials\StoredCredentials;
use Infixs\SquidCore\Support\OAuth\OAuthRevocation;
use Infixs\SquidCore\Support\OAuth\OAuthTokenStore;
use Infixs\SquidCore\Support\SquidCoreClient;
use Infixs\SquidCore\Support\SquidCoreUrls;
use Infixs\SquidCore\Support\TokenScopes;

defined( 'ABSPATH' ) || exit;

/**
 * Connection between this store and a SquidCore organization: credentials, handshake and recorded status.
 *
 * The store connects through "Conectar com SquidCore" (OAuth, see OAuthConnectService) or, in the manual connection,
 * with a plugin token; the OAuth connection wins when both exist.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class ConnectionService {

	private const HANDSHAKE_ENDPOINT = 'integrations/apps/woocommerce/handshake';

	/**
	 * Remembers that SquidCore was asked about the saved connection a moment ago.
	 */
	public const VERIFY_TRANSIENT = 'infixs_squidcore_connection_verified';

	private const VERIFY_INTERVAL = 5 * MINUTE_IN_SECONDS;

	private const ORGANIZATION_ENDPOINT = 'organizations/summary';

	/**
	 * Organization of an OAuth access token: organizations/summary refuses OAuth tokens.
	 */
	private const ME_ENDPOINT = 'me';

	/**
	 * @var SettingsRepository
	 */
	protected $settings;

	/**
	 * @var SquidCoreUrls
	 */
	protected $urls;

	/**
	 * @var string
	 */
	protected $pluginVersion;

	/**
	 * @var \Closure(string, CredentialProvider): SquidCoreClient
	 */
	protected $makeClient;

	/**
	 * @var SyncStatusRepository
	 */
	protected $syncStatus;

	/**
	 * @var ActivityRepository
	 */
	protected $activity;

	/**
	 * @var StoredCredentials
	 */
	protected $credentials;

	/**
	 * @var OAuthTokenStore
	 */
	protected $oauth;

	/**
	 * @var WooCommerceApiKeys
	 */
	protected $apiKeys;

	/**
	 * @var OAuthRevocation
	 */
	protected $revocation;

	/**
	 * @param SettingsRepository $settings
	 * @param SquidCoreUrls $urls API address the store talks to; the store owner never types it.
	 * @param string $pluginVersion
	 * @param \Closure $makeClient Builds a client for the given API URL and credential.
	 * @param SyncStatusRepository $syncStatus Outcome of the pushes, reset whenever the store points to another connection.
	 * @param ActivityRepository $activity Counters of the activity chart, reset with the outcome of the pushes.
	 * @param StoredCredentials $credentials The saved connection (OAuth or plugin token).
	 * @param OAuthTokenStore $oauth The OAuth connection.
	 * @param WooCommerceApiKeys $apiKeys WooCommerce key created for SquidCore by the OAuth connection.
	 * @param OAuthRevocation $revocation Ends the OAuth connection in SquidCore.
	 */
	public function __construct(
		SettingsRepository $settings,
		SquidCoreUrls $urls,
		string $pluginVersion,
		\Closure $makeClient,
		SyncStatusRepository $syncStatus,
		ActivityRepository $activity,
		StoredCredentials $credentials,
		OAuthTokenStore $oauth,
		WooCommerceApiKeys $apiKeys,
		OAuthRevocation $revocation
	) {
		$this->settings = $settings;
		$this->urls = $urls;
		$this->pluginVersion = $pluginVersion;
		$this->makeClient = $makeClient;
		$this->syncStatus = $syncStatus;
		$this->activity = $activity;
		$this->credentials = $credentials;
		$this->oauth = $oauth;
		$this->apiKeys = $apiKeys;
		$this->revocation = $revocation;
	}

	/**
	 * Connection settings for the form. The saved token never leaves the server.
	 *
	 * @return array{hasToken: bool, tokenMask: string, method: string} `hasToken` and `tokenMask` are about the plugin
	 *         token of the manual connection; `method` tells how the store connects (oauth, token or blank).
	 */
	public function settings(): array {
		$token = $this->credentials->manualToken();

		return [
			'hasToken' => $token !== '',
			'tokenMask' => $this->maskToken( $token ),
			'method' => $this->credentials->method(),
		];
	}

	/**
	 * Saves the plugin token of the manual connection and checks it against the API. A store connected through
	 * "Conectar com SquidCore" switches to the token only when the token connects: SquidCore then revokes the
	 * authorization.
	 *
	 * @param string $token Blank keeps the saved token.
	 *
	 * @return array|\WP_Error Result of the check.
	 */
	public function save( string $token ) {
		$invalid_url = $this->urls->apiUrlError();

		if ( $invalid_url ) {
			return $invalid_url;
		}

		$saved = $this->credentials->manualToken();
		$token = $token !== '' ? $token : $saved;

		if ( $token === '' ) {
			return new \WP_Error( 'missing_token', __( 'Please provide an access token.', 'infixs-squidcore-erp' ), [ 'status' => 400 ] );
		}

		$oauth = $this->oauth->get();
		$is_new_connection = $token !== $saved || $oauth !== null;

		if ( $oauth ) {
			// The authorization is only given up for a token that works: a mistyped one would leave the store disconnected.
			$trial = $this->handshake( new FixedCredential( $token ) );

			if ( ! $trial['connected'] ) {
				return new \WP_Error( 'token_not_connected', sprintf(
					/* translators: %s: why the token did not connect. */
					__( 'The token was not saved, so the connection made with Connect with SquidCore was kept. %s', 'infixs-squidcore-erp' ),
					$trial['message']
				), [ 'status' => 400 ] );
			}

			$this->leaveOAuth( $oauth, $trial['organization'] );
		}

		$this->settings->update( 'connection', [
			'token' => $token,
		] );

		// Older versions saved the API URL typed in the form; it is no longer read.
		$this->settings->delete( 'connection.api_url' );

		if ( $is_new_connection ) {
			$this->forgetConnectionHistory();
		}

		return $this->check( $this->credentials, true );
	}

	/**
	 * Checks a token without saving it; only a check of the saved connection updates the stored status.
	 *
	 * @param string $token Blank checks the saved connection (OAuth or plugin token).
	 *
	 * @return array|\WP_Error
	 */
	public function test( string $token ) {
		$invalid_url = $this->urls->apiUrlError();

		if ( $invalid_url ) {
			return $invalid_url;
		}

		if ( $token !== '' && $token !== $this->credentials->manualToken() ) {
			return $this->check( new FixedCredential( $token ), false );
		}

		if ( $this->credentials->method() === '' ) {
			return new \WP_Error( 'not_configured', __( 'Connect with SquidCore or fill in the access token before testing.', 'infixs-squidcore-erp' ), [ 'status' => 400 ] );
		}

		return $this->check( $this->credentials, true );
	}

	/**
	 * Asks SquidCore, at most once every few minutes for the whole store, whether it still accepts the saved connection,
	 * so a revoked connection shows while the panel is open even if nothing else is sent. Only a refusal changes the
	 * connection: a failure of the network says nothing about it.
	 */
	public function verify(): void {
		if ( ! $this->isConnected() || get_transient( self::VERIFY_TRANSIENT ) !== false ) {
			return;
		}

		set_transient( self::VERIFY_TRANSIENT, '1', self::VERIFY_INTERVAL );

		$response = $this->askHandshake( ( $this->makeClient )( $this->urls->apiUrl(), $this->credentials ) );

		if ( ! is_wp_error( $response ) || ApiErrorMessage::status( $response ) !== 401 ) {
			return;
		}

		$this->settings->update( 'connection', [
			'connected' => false,
			'last_error' => ApiErrorMessage::describe( $response ),
		] );
	}

	/**
	 * The store was just connected through "Conectar com SquidCore": the plugin token of the manual connection gives
	 * way, the outcome of the pushes starts over and the connection is checked.
	 *
	 * @return array{connected: bool, message: string, checkedAt: string, organization: array|null}
	 */
	public function useOAuth(): array {
		$oauth = $this->oauth->get();
		$kept_key = (int) $this->settings->get( 'connection.wc_key_id', 0 );

		// The key kept for a connection replaced by a plugin token gives way to the one just handed over.
		if ( $kept_key !== (int) ( $oauth['wc_key_id'] ?? 0 ) ) {
			$this->apiKeys->delete( $kept_key );
		}

		$this->settings->update( 'connection', [
			'token' => '',
			'wc_key_id' => 0,
		] );

		$this->forgetConnectionHistory();

		return $this->check( $this->credentials, true );
	}

	/**
	 * Forgets the connection, the recorded status and the outcome of the pushes. An OAuth connection is also ended in
	 * SquidCore, and the WooCommerce key the plugin created for it is removed, also when a plugin token replaced it.
	 *
	 * @return string|null Warning when SquidCore could not be told; the store is disconnected all the same.
	 */
	public function disconnect(): ?string {
		$oauth = $this->oauth->get();
		$warning = $oauth ? $this->forgetOAuth( $oauth, true ) : null;

		$this->apiKeys->delete( (int) $this->settings->get( 'connection.wc_key_id', 0 ) );

		$this->settings->update( 'connection', [
			'token' => '',
			'checked_api_url' => '',
			'connected' => false,
			'organization' => '',
			'last_error' => '',
			'last_handshake_at' => '',
			'ai_ncm_available' => false,
			'invoice_scopes' => '',
			'wc_key_id' => 0,
		] );

		$this->forgetConnectionHistory();

		return $warning;
	}

	/**
	 * The outcome and the activity of the pushes, and whatever was read through the connection, belong to it: whenever
	 * the store points to another one (new credential, other API, none), the dashboard starts over.
	 */
	private function forgetConnectionHistory(): void {
		$this->syncStatus->clear();
		$this->activity->clear();

		/**
		 * Fires when the store points to another SquidCore connection (new credential, other API, none): what was read
		 * through the previous one (the account, its plan, its use) no longer holds.
		 *
		 * @since 1.5.0
		 */
		do_action( 'infixs_squidcore_connection_changed' );
	}

	/**
	 * The store switches from "Conectar com SquidCore" to a plugin token that connects: SquidCore revokes the
	 * authorization. The WooCommerce key created for it stays while the token belongs to the same organization, whose
	 * integration goes on calling the store with it, and is kept on record so a disconnection still removes it. The
	 * token of another organization takes the key away now.
	 *
	 * @param array $oauth The OAuth connection.
	 * @param array|null $organization Organization of the token, as the handshake told.
	 */
	private function leaveOAuth( array $oauth, ?array $organization ): void {
		$other_organization = $this->isOtherOrganization( $oauth, $organization );

		$this->forgetOAuth( $oauth, $other_organization );

		if ( $other_organization ) {
			return;
		}

		$key_id = (int) ( $oauth['wc_key_id'] ?? 0 );
		$kept_key = (int) $this->settings->get( 'connection.wc_key_id', 0 );

		if ( $kept_key !== $key_id ) {
			$this->apiKeys->delete( $kept_key );
		}

		$this->settings->update( 'connection', [
			'wc_key_id' => $key_id,
		] );
	}

	/**
	 * Whether a token belongs to another organization than the OAuth connection; false when either is unknown.
	 *
	 * @param array $oauth The OAuth connection.
	 * @param array|null $organization Organization of the token.
	 */
	private function isOtherOrganization( array $oauth, ?array $organization ): bool {
		$connected = (string) ( $oauth['organization_id'] ?? '' );

		// Connections made before the organization was kept with them: the one the last check confirmed.
		if ( $connected === '' ) {
			$stored = $this->settings->get( 'connection.organization' );
			$connected = is_array( $stored ) ? (string) ( $stored['id'] ?? '' ) : '';
		}

		$token_organization = (string) ( $organization['id'] ?? '' );

		return $connected !== '' && $token_organization !== '' && $connected !== $token_organization;
	}

	/**
	 * Ends the OAuth connection: SquidCore revokes the authorization (and marks the plugin disconnected) and the
	 * stored token is dropped.
	 *
	 * @param bool $deleteKey Also remove the WooCommerce key created for SquidCore.
	 *
	 * @return string|null Warning when SquidCore could not be told.
	 */
	private function forgetOAuth( array $oauth, bool $deleteKey ): ?string {
		$warning = null;

		// A token SquidCore refused, or issued by another API, is not sent.
		$token = $this->credentials->token();
		$error = $token !== '' ? $this->revocation->end( $this->urls->apiUrl(), $token ) : null;

		if ( $error ) {
			$warning = sprintf(
				/* translators: %s: why the request to SquidCore failed. */
				__( 'The store was disconnected, but SquidCore could not be told (%s). Revoke the access of "SquidCore para WooCommerce" in SquidCore, under Settings › Connected apps.', 'infixs-squidcore-erp' ),
				ApiErrorMessage::describe( $error )
			);
		}

		if ( $deleteKey ) {
			$this->apiKeys->delete( (int) ( $oauth['wc_key_id'] ?? 0 ) );
		}

		$this->oauth->delete();

		return $warning;
	}

	/**
	 * @return array{configured: bool, connected: bool, method: string|null, needsReconnect: bool, apiUrl: string, apiUrlOverridden: bool, apiUrlError: string|null, lastCheckAt: string|null, lastError: string|null, organization: array|null, aiNcmAvailable: bool, invoicePermission: bool|null}
	 */
	public function status(): array {
		$invalid_url = $this->urls->apiUrlError();
		$last_check = $this->settings->string( 'connection.last_handshake_at', '' );
		$connected = $this->isConnected();
		$method = $this->credentials->method();

		return [
			'configured' => $method !== '',
			'connected' => $connected,
			'method' => $method !== '' ? $method : null,
			'needsReconnect' => $this->credentials->needsReconnect(),
			'apiUrl' => $this->urls->apiUrl(),
			'apiUrlOverridden' => $this->urls->isApiUrlOverridden(),
			'apiUrlError' => $invalid_url ? $invalid_url->get_error_message() : null,
			'lastCheckAt' => $last_check !== '' ? $last_check : null,
			'lastError' => $connected ? null : $this->connectionProblem(),
			'organization' => $connected ? $this->storedOrganization() : null,
			'aiNcmAvailable' => $this->aiNcmAvailable(),
			'invoicePermission' => $connected ? $this->invoicePermission() : null,
		];
	}

	/**
	 * Whether the saved connection was confirmed by the API the plugin talks to now, so the pushes can go out. An OAuth
	 * connection SquidCore refused since then is not.
	 */
	public function isConnected(): bool {
		return $this->credentials->token() !== ''
			&& $this->urls->isApiUrlUsable()
			&& $this->isCheckedAgainstCurrentApi()
			&& $this->settings->boolean( 'connection.connected', false );
	}

	/**
	 * Whether SquidCore can fill a missing NCM with AI for this store: an AI is connected in SquidCore (as of the last
	 * handshake) and the store is connected.
	 */
	public function aiNcmAvailable(): bool {
		return $this->isConnected() && $this->settings->boolean( 'connection.ai_ncm_available', false );
	}

	/**
	 * Why the saved connection cannot be used, when there is one.
	 */
	private function connectionProblem(): ?string {
		if ( $this->credentials->method() === '' ) {
			return null;
		}

		$invalid_url = $this->urls->apiUrlError();

		if ( $invalid_url ) {
			return $invalid_url->get_error_message();
		}

		if ( $this->credentials->needsReconnect() ) {
			$oauth = $this->oauth->get();

			return (string) ( $oauth['api_url'] ?? '' ) !== $this->urls->apiUrl()
				? __( 'The SquidCore API address changed since the store was connected. Connect with SquidCore again.', 'infixs-squidcore-erp' )
				: ApiErrorMessage::reconnectMessage();
		}

		if ( $this->settings->string( 'connection.last_handshake_at', '' ) !== '' && ! $this->isCheckedAgainstCurrentApi() ) {
			return __( 'The SquidCore API address changed since the last check. Test the connection again.', 'infixs-squidcore-erp' );
		}

		$last_error = $this->settings->string( 'connection.last_error', '' );

		return $last_error !== '' ? $last_error : null;
	}

	/**
	 * The handshake result only holds for the API it was made against (INFIXS_SQUIDCORE_API_URL may change).
	 */
	private function isCheckedAgainstCurrentApi(): bool {
		return $this->settings->string( 'connection.checked_api_url', '' ) === $this->urls->apiUrl();
	}

	/**
	 * @param CredentialProvider $credential
	 * @param bool $persist Record the result as the status of the saved connection.
	 *
	 * @return array{connected: bool, message: string, checkedAt: string, organization: array|null}
	 */
	private function check( CredentialProvider $credential, bool $persist ): array {
		$result = $this->handshake( $credential );
		$connected = $result['connected'];

		if ( $persist ) {
			// The history of the pushes belongs to the API they went to.
			if ( $this->settings->string( 'connection.checked_api_url', '' ) !== $this->urls->apiUrl() ) {
				$this->forgetConnectionHistory();
			}

			$this->settings->update( 'connection', [
				'checked_api_url' => $this->urls->apiUrl(),
				'connected' => $connected,
				'last_handshake_at' => $result['checkedAt'],
				'last_error' => $connected ? '' : $result['message'],
				'organization' => $result['organization'] ?? '',
				'ai_ncm_available' => $connected && ! empty( $result['data']['aiNcmAvailable'] ),
				'invoice_scopes' => $connected && is_array( $result['data'] ) ? $this->invoiceScopes( $result['data'], $credential->method() ) : '',
			] );
		}

		return [
			'connected' => $connected,
			'message' => $result['message'],
			'checkedAt' => $result['checkedAt'],
			'organization' => $this->publicOrganization( $result['organization'] ),
		];
	}

	/**
	 * Asks the API whether a credential connects the store, and to which organization.
	 *
	 * @return array{connected: bool, message: string, checkedAt: string, organization: array{id: string, name: string, slug: string}|null, data: array|null}
	 *         `data` is the answer of the handshake.
	 */
	private function handshake( CredentialProvider $credential ): array {
		/** @var SquidCoreClient $client */
		$client = ( $this->makeClient )( $this->urls->apiUrl(), $credential );

		$response = $this->askHandshake( $client );

		$connected = ! is_wp_error( $response ) && ! empty( $response['data']['connected'] );

		if ( is_wp_error( $response ) ) {
			$message = ApiErrorMessage::describe( $response );
		} elseif ( $connected ) {
			$message = __( 'Connection established successfully.', 'infixs-squidcore-erp' );
		} else {
			$message = __( 'The SquidCore API did not confirm the connection.', 'infixs-squidcore-erp' );
		}

		return [
			'connected' => $connected,
			'message' => $message,
			'checkedAt' => gmdate( 'c' ),
			'organization' => $connected ? $this->fetchOrganization( $client, $credential->method() ) : null,
			'data' => ! is_wp_error( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : null,
		];
	}

	/**
	 * @param SquidCoreClient $client
	 *
	 * @return array|\WP_Error The answer of SquidCore.
	 */
	private function askHandshake( SquidCoreClient $client ) {
		return $client->post( self::HANDSHAKE_ENDPOINT, [
			'siteUrl' => home_url(),
			'pluginVersion' => $this->pluginVersion,
		] );
	}

	/**
	 * Whether the credential may use the invoice routes, as the handshake tells: 'granted', 'missing', or blank when
	 * the API does not list the scopes.
	 *
	 * @param string $method How the checked credential connects (CredentialProvider::METHOD_*).
	 */
	private function invoiceScopes( array $handshake, string $method ): string {
		if ( $method === CredentialProvider::METHOD_OAUTH ) {
			return TokenScopes::coverInvoices( $this->oauthScopes( $handshake ) ) ? 'granted' : 'missing';
		}

		if ( ! isset( $handshake['scopes'] ) || ! is_array( $handshake['scopes'] ) ) {
			return '';
		}

		// A token without scopes keeps every permission of the user who generated it, like SquidCore reads it.
		if ( $handshake['scopes'] === [] ) {
			return 'granted';
		}

		return TokenScopes::coverInvoices( $handshake['scopes'] ) ? 'granted' : 'missing';
	}

	/**
	 * Scopes of the OAuth authorization. An OAuth token carries only the scopes it was granted, so none means none.
	 *
	 * @return array
	 */
	private function oauthScopes( array $handshake ): array {
		foreach ( [ 'oauthScopes', 'scopes' ] as $key ) {
			if ( ! empty( $handshake[ $key ] ) && is_array( $handshake[ $key ] ) ) {
				return $handshake[ $key ];
			}
		}

		// SquidCore versions that only tell the scopes of plugin tokens: the scopes granted with the access token.
		$oauth = $this->oauth->get();

		return TokenScopes::parse( (string) ( $oauth['scope'] ?? '' ) );
	}

	/**
	 * Whether the saved token carries the invoice (NF-e) scopes, as the last handshake told.
	 *
	 * @return bool|null Null when the API never told the scopes of the token.
	 */
	public function invoicePermission(): ?bool {
		$scopes = $this->settings->string( 'connection.invoice_scopes', '' );

		return $scopes === '' ? null : $scopes === 'granted';
	}

	/**
	 * Only the id, name and slug are kept; the answers also carry tax, address and user data.
	 *
	 * @param string $method How the checked credential connects: an OAuth token reads its organization in /me, the
	 *                       one the merchant chose when authorizing.
	 *
	 * @return array{id: string, name: string, slug: string}|null
	 */
	private function fetchOrganization( SquidCoreClient $client, string $method ): ?array {
		if ( $method === CredentialProvider::METHOD_OAUTH ) {
			$response = $client->get( self::ME_ENDPOINT );
			$organization = is_wp_error( $response ) ? null : ( $response['activeContext']['organization'] ?? ( $response['data']['activeContext']['organization'] ?? null ) );
		} else {
			$response = $client->get( self::ORGANIZATION_ENDPOINT );
			$organization = is_wp_error( $response ) ? null : ( $response['data'] ?? null );
		}

		if ( ! is_array( $organization ) || empty( $organization['name'] ) ) {
			return null;
		}

		return [
			'id' => sanitize_text_field( (string) ( $organization['id'] ?? '' ) ),
			'name' => sanitize_text_field( (string) $organization['name'] ),
			'slug' => sanitize_title( (string) ( $organization['slug'] ?? '' ) ),
		];
	}

	/**
	 * @return array{name: string, slug: string}|null
	 */
	private function storedOrganization(): ?array {
		return $this->publicOrganization( $this->settings->get( 'connection.organization' ) );
	}

	/**
	 * What the screens show of an organization: its name and slug.
	 *
	 * @param mixed $organization
	 *
	 * @return array{name: string, slug: string}|null
	 */
	private function publicOrganization( $organization ): ?array {
		if ( ! is_array( $organization ) || empty( $organization['name'] ) ) {
			return null;
		}

		return [
			'name' => sanitize_text_field( (string) $organization['name'] ),
			'slug' => sanitize_title( (string) ( $organization['slug'] ?? '' ) ),
		];
	}

	private function maskToken( string $token ): string {
		if ( $token === '' ) {
			return '';
		}

		$prefix = '';

		foreach ( [ 'sq_pat_', 'pat_' ] as $known_prefix ) {
			if ( strpos( $token, $known_prefix ) === 0 ) {
				$prefix = $known_prefix;
				break;
			}
		}

		$last4 = strlen( $token ) > 12 ? substr( $token, -4 ) : '';

		return $prefix . str_repeat( '•', 8 ) . $last4;
	}
}
