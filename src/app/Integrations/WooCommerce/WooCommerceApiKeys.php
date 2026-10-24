<?php

namespace Infixs\SquidCore\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce REST API keys the plugin creates for SquidCore when the store connects through "Conectar com SquidCore",
 * written the way WooCommerce writes the keys of its own authorization screen (WC_Auth): the secret is sent once and
 * only the key id is kept.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class WooCommerceApiKeys {

	/**
	 * Name of the keys, as WooCommerce › Settings › Advanced › REST API lists them: "SquidCore - API (date)".
	 */
	public const APP_NAME = 'SquidCore';

	/**
	 * Creates a read/write key of a user.
	 *
	 * @param int $userId Owner of the key; the REST requests of SquidCore act as this user.
	 *
	 * @return array{id: int, consumerKey: string, consumerSecret: string}|\WP_Error
	 */
	public function create( int $userId ) {
		global $wpdb;

		if ( ! function_exists( 'wc_rand_hash' ) || ! function_exists( 'wc_api_hash' ) ) {
			return new \WP_Error( 'woocommerce_inactive', __( 'WooCommerce is not active.', 'infixs-squidcore-erp' ) );
		}

		$consumer_key = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the table of the WooCommerce keys has no API to write to.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'woocommerce_api_keys',
			[
				'user_id' => $userId,
				'description' => sprintf( '%s - API (%s)', self::APP_NAME, gmdate( 'Y-m-d H:i:s' ) ),
				'permissions' => 'read_write',
				'consumer_key' => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key' => substr( $consumer_key, -7 ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'woocommerce_key_failed', __( 'Could not create the WooCommerce REST API key for SquidCore.', 'infixs-squidcore-erp' ) );
		}

		return [
			'id' => (int) $wpdb->insert_id,
			'consumerKey' => $consumer_key,
			'consumerSecret' => $consumer_secret,
		];
	}

	/**
	 * Removes a key the plugin created. Only a key still named as the plugin names them is removed: the merchant may
	 * have revoked it by hand, and the database may have given its id to the key of another app since then.
	 */
	public function delete( int $keyId ): void {
		global $wpdb;

		if ( $keyId <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as WooCommerce revokes a key.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d AND description LIKE %s",
			$keyId,
			$wpdb->esc_like( self::APP_NAME . ' - API (' ) . '%'
		) );
	}
}
