<?php

namespace Infixs\SquidCore\Support\Credentials;

use Infixs\SquidCore\AvelPress\Config\SettingsRepository;
use Infixs\SquidCore\Support\FreshOption;
use Infixs\SquidCore\Support\OAuth\OAuthTokenStore;
use Infixs\SquidCore\Support\SquidCoreUrls;

defined( 'ABSPATH' ) || exit;

/**
 * The connection the store saved: the "Conectar com SquidCore" authorization when there is one, otherwise the plugin
 * token of the manual connection (stores connected before OAuth keep working untouched).
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class StoredCredentials implements CredentialProvider {

	/**
	 * Option of the plugin settings (AvelPress names it after the app id).
	 */
	private const SETTINGS_OPTION = 'infixs_squidcore_settings';

	/**
	 * @var SettingsRepository
	 */
	protected $settings;

	/**
	 * @var OAuthTokenStore
	 */
	protected $oauth;

	/**
	 * @var SquidCoreUrls
	 */
	protected $urls;

	/**
	 * This process saw an OAuth connection.
	 *
	 * @var bool
	 */
	private $sawOAuth = false;

	public function __construct( SettingsRepository $settings, OAuthTokenStore $oauth, SquidCoreUrls $urls ) {
		$this->settings = $settings;
		$this->oauth = $oauth;
		$this->urls = $urls;
	}

	/**
	 * An authorization SquidCore refused, or issued by another API than the one in use now, is never sent: the token
	 * would only reach an address that does not know it.
	 */
	public function token(): string {
		$oauth = $this->currentOAuth();

		if ( $oauth ) {
			return $this->isUsable( $oauth ) ? $oauth['access_token'] : '';
		}

		return $this->manualToken();
	}

	public function method(): string {
		if ( $this->currentOAuth() ) {
			return self::METHOD_OAUTH;
		}

		return $this->manualToken() !== '' ? self::METHOD_TOKEN : '';
	}

	public function rejected( string $token ): void {
		if ( $token !== '' ) {
			$this->oauth->markNeedsReconnect( $token );
		}
	}

	/**
	 * Whether the store is connected through OAuth but has to connect again: SquidCore refused the token, or the API
	 * address changed since the authorization.
	 */
	public function needsReconnect(): bool {
		$oauth = $this->currentOAuth();

		return $oauth !== null && ! $this->isUsable( $oauth );
	}

	/**
	 * Plugin token of the manual connection, blank when there is none.
	 */
	public function manualToken(): string {
		$token = (string) $this->settings->get( 'connection.token', '' );

		return $token === '' && $this->settingsPredateTheToken() ? $this->savedManualToken() : $token;
	}

	private function currentOAuth(): ?array {
		$oauth = $this->oauth->get();

		if ( $oauth ) {
			$this->sawOAuth = true;
		}

		return $oauth;
	}

	/**
	 * The settings are read once, when the process starts, and an OAuth connection blanks the plugin token in them.
	 * When the OAuth connection this process saw is gone and this process did not remove it, another request replaced
	 * it, likely by a plugin token saved while a long background batch runs: the token in memory is stale.
	 */
	private function settingsPredateTheToken(): bool {
		return $this->sawOAuth && ! $this->oauth->wasRemovedHere() && ! $this->currentOAuth();
	}

	private function savedManualToken(): string {
		$settings = FreshOption::get( self::SETTINGS_OPTION );
		$token = is_array( $settings ) && isset( $settings['connection'] ) && is_array( $settings['connection'] ) ? ( $settings['connection']['token'] ?? '' ) : '';

		return is_string( $token ) ? $token : '';
	}

	private function isUsable( array $oauth ): bool {
		return empty( $oauth['needs_reconnect'] ) && (string) ( $oauth['api_url'] ?? '' ) === $this->urls->apiUrl();
	}
}
