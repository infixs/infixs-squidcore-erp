<?php

namespace Infixs\SquidCore\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads an option straight from the table, past the object cache, which a long background batch keeps for its whole
 * run: the value another request wrote after this one started.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class FreshOption {

	/**
	 * @return mixed|null The stored value, null when the option does not exist.
	 */
	public static function get( string $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh on purpose, see the class.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );

		return null === $value ? null : maybe_unserialize( $value );
	}
}
