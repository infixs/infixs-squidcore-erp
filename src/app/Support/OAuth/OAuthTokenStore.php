<?php

namespace Infixs\SquidCore\Support\OAuth;

use Infixs\SquidCore\Support\FreshOption;

defined( 'ABSPATH' ) || exit;

/**
 * The "Conectar com SquidCore" connection: the access token SquidCore issued and what the plugin created with it.
 *
 * Kept in an option of its own, outside `infixs_squidcore_settings`: the settings are read once per request and saved
 * whole, so any request that saves them would write back the token it had read. Reads go straight to the table, so a
 * long background batch sees a connection made, replaced or removed by another request.
 *
 * Stored fields: access_token, scope, client_id, issuer, api_url, connected_at, wc_key_id, organization_id (the
 * organization SquidCore connected the store to) and, once SquidCore refused the token, needs_reconnect.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class OAuthTokenStore {

	public const OPTION = 'infixs_squidcore_oauth';

	/**
	 * The last change this process made to the connection removed it.
	 *
	 * @var bool
	 */
	private $removedHere = false;

	/**
	 * @return array|null Null when the store is not connected through OAuth.
	 */
	public function get(): ?array {
		$data = FreshOption::get( self::OPTION );

		if ( ! is_array( $data ) || empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			return null;
		}

		return $data;
	}

	public function save( array $data ): void {
		$this->removedHere = false;
		$this->forgetCache();
		update_option( self::OPTION, $data, false );
	}

	public function delete(): void {
		$this->removedHere = true;
		$this->forgetCache();
		delete_option( self::OPTION );
	}

	/**
	 * Whether this process removed the connection (and wrote what replaced it), rather than another request.
	 */
	public function wasRemovedHere(): bool {
		return $this->removedHere;
	}

	/**
	 * SquidCore no longer accepts the token: it is not sent again until the store connects anew.
	 *
	 * @param string $token The refused token; a connection made since then keeps working.
	 */
	public function markNeedsReconnect( string $token ): void {
		$data = $this->get();

		if ( ! $data || $data['access_token'] !== $token || ! empty( $data['needs_reconnect'] ) ) {
			return;
		}

		$data['needs_reconnect'] = true;
		$this->save( $data );
	}

	/**
	 * update_option() and delete_option() compare with the cached value, which another request may have changed.
	 */
	private function forgetCache(): void {
		wp_cache_delete( self::OPTION, 'options' );

		$not_options = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $not_options ) && isset( $not_options[ self::OPTION ] ) ) {
			unset( $not_options[ self::OPTION ] );
			wp_cache_set( 'notoptions', $not_options, 'options' );
		}
	}
}
