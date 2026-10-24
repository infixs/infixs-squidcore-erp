<?php

namespace Infixs\SquidCore\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of the latest pushes sent to SquidCore, kept outside the settings so cron pushes never rewrite them.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class SyncStatusRepository {

	private const LAST_SUCCESS_OPTION = 'infixs_squidcore_sync_last_success';

	private const LAST_FAILURE_OPTION = 'infixs_squidcore_sync_last_failure';

	/**
	 * A success for the entity of the last failure resolves it: every push carries the full current state of the entity.
	 *
	 * @param string $topic
	 * @param int $entityId
	 * @param bool $ignored SquidCore accepted the event but discarded it (inbound sync off on its side).
	 */
	public function recordSuccess( string $topic, int $entityId, bool $ignored = false ): void {
		update_option( self::LAST_SUCCESS_OPTION, [
			'topic' => $topic,
			'entity_id' => $entityId,
			'ignored' => $ignored,
			'at' => gmdate( 'c' ),
		], false );

		$last_failure = $this->lastFailure();

		if ( $last_failure && $this->sameEntity( $last_failure, $topic, $entityId ) ) {
			delete_option( self::LAST_FAILURE_OPTION );
		}
	}

	public function recordFailure( string $topic, int $entityId, string $message ): void {
		update_option( self::LAST_FAILURE_OPTION, [
			'topic' => $topic,
			'entity_id' => $entityId,
			'message' => $message,
			'at' => gmdate( 'c' ),
		], false );
	}

	/**
	 * @return array{topic: string, entity_id: int, ignored: bool, at: string}|null
	 */
	public function lastSuccess(): ?array {
		return $this->read( self::LAST_SUCCESS_OPTION );
	}

	/**
	 * @return array{topic: string, entity_id: int, message: string, at: string}|null
	 */
	public function lastFailure(): ?array {
		return $this->read( self::LAST_FAILURE_OPTION );
	}

	/**
	 * Forgets both outcomes, so a new connection does not show what happened to the previous one.
	 */
	public function clear(): void {
		delete_option( self::LAST_SUCCESS_OPTION );
		delete_option( self::LAST_FAILURE_OPTION );
	}

	/**
	 * @param array $record Stored outcome.
	 * @param string $topic e.g. "product.updated"; the entity type is the part before the dot.
	 * @param int $entityId
	 */
	private function sameEntity( array $record, string $topic, int $entityId ): bool {
		return (int) ( $record['entity_id'] ?? 0 ) === $entityId
			&& explode( '.', (string) ( $record['topic'] ?? '' ) )[0] === explode( '.', $topic )[0];
	}

	private function read( string $option ): ?array {
		$value = get_option( $option, null );

		return is_array( $value ) && ! empty( $value['at'] ) ? $value : null;
	}
}
