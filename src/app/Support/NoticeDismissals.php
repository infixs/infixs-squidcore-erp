<?php

namespace Infixs\SquidCore\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Notices a user dismissed, for good or until a date.
 *
 * Kept in a user option, which WordPress names after the site: dismissing a notice in one store of a network does not
 * hide it in the others. Expired dismissals are dropped on the next write.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class NoticeDismissals {

	/**
	 * User option: notice id => unix time it shows again, 0 for never.
	 */
	public const OPTION = 'infixs_squidcore_dismissed_notices';

	public function isDismissed( int $userId, string $id ): bool {
		$until = $this->all( $userId )[ $id ] ?? null;

		return $until !== null && ( $until === 0 || $until > time() );
	}

	/**
	 * Hides the notice for a while.
	 *
	 * @return int Unix time it shows again.
	 */
	public function snooze( int $userId, string $id, int $seconds ): int {
		$until = time() + max( 1, $seconds );
		$this->put( $userId, $id, $until );

		return $until;
	}

	public function dismissForever( int $userId, string $id ): void {
		$this->put( $userId, $id, 0 );
	}

	/**
	 * Shows the notice again.
	 */
	public function forget( int $userId, string $id ): void {
		if ( $userId <= 0 ) {
			return;
		}

		$all = $this->all( $userId );
		unset( $all[ $id ] );
		$this->save( $userId, $all );
	}

	private function put( int $userId, string $id, int $until ): void {
		if ( $userId <= 0 ) {
			return;
		}

		$all = $this->all( $userId );
		$all[ $id ] = $until;
		$this->save( $userId, $all );
	}

	/**
	 * @return array<string, int>
	 */
	private function all( int $userId ): array {
		if ( $userId <= 0 ) {
			return [];
		}

		$stored = get_user_option( self::OPTION, $userId );
		$all = [];

		foreach ( is_array( $stored ) ? $stored : [] as $id => $until ) {
			if ( is_string( $id ) && is_numeric( $until ) ) {
				$all[ $id ] = max( 0, (int) $until );
			}
		}

		return $all;
	}

	/**
	 * @param array<string, int> $all
	 */
	private function save( int $userId, array $all ): void {
		$now = time();
		$all = array_filter( $all, function ( int $until ) use ( $now ): bool {
			return $until === 0 || $until > $now;
		} );

		if ( ! $all ) {
			delete_user_option( $userId, self::OPTION );
			return;
		}

		update_user_option( $userId, self::OPTION, $all );
	}
}
