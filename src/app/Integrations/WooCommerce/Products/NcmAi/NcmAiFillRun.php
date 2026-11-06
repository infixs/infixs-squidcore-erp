<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

defined( 'ABSPATH' ) || exit;

/**
 * Shape of one run of the AI filling of the NCM, as stored and as the admin app sees it.
 *
 * The stored run is the public one plus the progress of the engine: the products chosen (bulk action), the cursor, the
 * products after the cursor already settled while their batch waits to ask the AI again for the others (skip), the
 * heartbeat, the failures of SquidCore in a row and who started it.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
final class NcmAiFillRun {

	public const RUNNING = 'running';

	public const STOPPED = 'stopped';

	public const DONE = 'done';

	public const FAILED = 'failed';

	/**
	 * Every product without NCM (the settings) or only the chosen ones without NCM (the bulk action of the products list).
	 */
	public const SCOPE_ALL = 'all';

	public const SCOPE_SELECTED = 'selected';

	/**
	 * Products a bulk action may choose at once.
	 */
	public const MAX_SELECTED = 5000;

	/**
	 * "a" + UTC date and time + 6 hex digits.
	 */
	private const ID_PATTERN = '/^a\d{14}[a-f0-9]{6}$/';

	private const STATUSES = [ self::RUNNING, self::STOPPED, self::DONE, self::FAILED ];

	/**
	 * A new run, from the first product.
	 *
	 * @param int[]|null $ids   Products chosen; null for every product without NCM.
	 * @param int        $total Products without NCM it will go through.
	 */
	public static function create( ?array $ids, int $total, \WP_User $user ): array {
		$now = time();

		return self::normalize( [
			'id' => 'a' . gmdate( 'YmdHis', $now ) . bin2hex( random_bytes( 3 ) ),
			'status' => self::RUNNING,
			'scope' => $ids === null ? self::SCOPE_ALL : self::SCOPE_SELECTED,
			'ids' => $ids ?? [],
			'total' => $total,
			'startedAt' => $now,
			'startedBy' => $user->display_name,
			'startedById' => $user->ID,
			'heartbeat' => $now,
		] );
	}

	public static function isValidId( string $id ): bool {
		return (bool) preg_match( self::ID_PATTERN, $id );
	}

	/**
	 * @param array|null $run
	 */
	public static function isRunning( $run ): bool {
		return is_array( $run ) && ( $run['status'] ?? '' ) === self::RUNNING;
	}

	/**
	 * Products chosen by the bulk action, null for every product without NCM.
	 *
	 * @return int[]|null
	 */
	public static function only( array $run ): ?array {
		return $run['scope'] === self::SCOPE_SELECTED ? $run['ids'] : null;
	}

	/**
	 * The stored run with every field, null when there is none.
	 *
	 * @param mixed $value
	 */
	public static function normalize( $value ): ?array {
		if ( ! is_array( $value ) || ! isset( $value['id'] ) || ! is_string( $value['id'] ) || ! self::isValidId( $value['id'] ) ) {
			return null;
		}

		$ids = self::idList( $value['ids'] ?? null );

		return [
			'id' => $value['id'],
			'status' => in_array( $value['status'] ?? '', self::STATUSES, true ) ? $value['status'] : self::FAILED,
			'scope' => ( $value['scope'] ?? '' ) === self::SCOPE_SELECTED ? self::SCOPE_SELECTED : self::SCOPE_ALL,
			'ids' => $ids,
			'cursor' => max( 0, (int) ( $value['cursor'] ?? 0 ) ),
			'skip' => self::idList( $value['skip'] ?? null ),
			'total' => max( 0, (int) ( $value['total'] ?? 0 ) ),
			'processed' => max( 0, (int) ( $value['processed'] ?? 0 ) ),
			'filled' => max( 0, (int) ( $value['filled'] ?? 0 ) ),
			'failed' => max( 0, (int) ( $value['failed'] ?? 0 ) ),
			'retries' => max( 0, (int) ( $value['retries'] ?? 0 ) ),
			'startedAt' => max( 0, (int) ( $value['startedAt'] ?? 0 ) ),
			'finishedAt' => isset( $value['finishedAt'] ) && $value['finishedAt'] !== null ? (int) $value['finishedAt'] : null,
			'heartbeat' => max( 0, (int) ( $value['heartbeat'] ?? 0 ) ),
			'startedBy' => isset( $value['startedBy'] ) ? sanitize_text_field( (string) $value['startedBy'] ) : '',
			'startedById' => max( 0, (int) ( $value['startedById'] ?? 0 ) ),
			'error' => isset( $value['error'] ) && is_string( $value['error'] ) && $value['error'] !== '' ? $value['error'] : null,
		];
	}

	/**
	 * Positive product ids, once each, up to MAX_SELECTED.
	 *
	 * @param mixed $value
	 *
	 * @return int[]
	 */
	public static function idList( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $value ), function ( $id ) {
			return $id > 0;
		} ) ) );

		return array_slice( $ids, 0, self::MAX_SELECTED );
	}

	/**
	 * What the admin app reads (TS NcmAiFillRun).
	 */
	public static function toPublic( array $run ): array {
		return [
			'id' => $run['id'],
			'status' => $run['status'],
			'scope' => $run['scope'],
			'total' => max( $run['total'], $run['processed'] ),
			'processed' => $run['processed'],
			'filled' => $run['filled'],
			'failed' => $run['failed'],
			'startedAt' => gmdate( 'c', $run['startedAt'] ),
			'finishedAt' => $run['finishedAt'] !== null ? gmdate( 'c', $run['finishedAt'] ) : null,
			'startedBy' => $run['startedBy'],
			'error' => $run['error'],
		];
	}
}
