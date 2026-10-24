<?php

namespace Infixs\SquidCore\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Lock shared by every request (front, admin, cron), kept as a row of the options table.
 *
 * Written straight in the table: `INSERT IGNORE` lets only one request create the row, which add_option() (check,
 * then insert) does not, and the options cache never answers for it. A lock older than its TTL belongs to a request
 * that died and is taken over by the one request that manages to remove it.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class OptionLock {

	/**
	 * Takes the lock.
	 *
	 * @param string $name Option name of the lock.
	 * @param int    $ttl  Seconds after which a held lock counts as abandoned.
	 *
	 * @return bool False when another request holds it.
	 */
	public function acquire( string $name, int $ttl ): bool {
		global $wpdb;

		if ( $this->insert( $name ) ) {
			return true;
		}

		$since = $this->rawSince( $name );

		if ( $since !== null && time() - (int) $since < $ttl ) {
			return false;
		}

		// Only the request that removes the lock of the dead one takes it over.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock must not be cached.
		$removed = $since === null || $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $since ) ) === 1;

		return $removed && $this->insert( $name );
	}

	public function release( string $name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock must not be cached.
		$wpdb->delete( $wpdb->options, [ 'option_name' => $name ] );
	}

	/**
	 * Unix time the lock was taken, null when nobody holds it.
	 */
	public function heldSince( string $name ): ?int {
		$since = $this->rawSince( $name );

		return $since === null ? null : (int) $since;
	}

	private function rawSince( string $name ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock must not be cached.
		$since = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		return $since === null ? null : (string) $since;
	}

	private function insert( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock must not be cached.
		return $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) ) === 1;
	}
}
