<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

defined( 'ABSPATH' ) || exit;

/**
 * Shape of one run of the import (a preview, an apply or an undo), as stored and as the admin app sees it.
 *
 * The stored run is the public one plus the progress of the engine: cursor, heartbeat, phase, the NCM written so far
 * (for the catch-all signal), who started it and whether the "import finished" notice is due.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
final class FiscalImportRun {

	public const MODE_PREVIEW = 'preview';

	public const MODE_APPLY = 'apply';

	public const MODE_UNDO = 'undo';

	public const RUNNING = 'running';

	/**
	 * A finished preview: nothing written, ready to apply.
	 */
	public const READY = 'ready';

	public const STOPPED = 'stopped';

	public const DONE = 'done';

	public const FAILED = 'failed';

	/**
	 * Phases of an apply: plan (and write) every product, then verify the products written.
	 */
	public const PHASE_PLAN = 'plan';

	public const PHASE_VERIFY = 'verify';

	/**
	 * "r" + UTC date and time + 6 hex digits.
	 */
	private const ID_PATTERN = '/^r\d{14}[a-f0-9]{6}$/';

	private const MODES = [ self::MODE_PREVIEW, self::MODE_APPLY, self::MODE_UNDO ];

	private const STATUSES = [ self::RUNNING, self::READY, self::STOPPED, self::DONE, self::FAILED ];

	/**
	 * A new run, running from the first product.
	 *
	 * @param string      $mode     MODE_* constant.
	 * @param array       $choices  FiscalImportChoices::toArray().
	 * @param int         $total    Products to go through.
	 * @param string|null $parentId Apply: the preview it comes from. Undo: the apply it undoes.
	 */
	public static function create( string $mode, array $choices, int $total, ?string $parentId, \WP_User $user ): array {
		$now = time();

		return self::normalize( [
			'id' => 'r' . gmdate( 'YmdHis', $now ) . bin2hex( random_bytes( 3 ) ),
			'mode' => $mode,
			'status' => self::RUNNING,
			'choices' => $choices,
			'total' => $total,
			'startedAt' => $now,
			'startedBy' => $user->display_name,
			'startedById' => $user->ID,
			'parentId' => $parentId,
			'heartbeat' => $now,
		] );
	}

	public static function isValidId( string $id ): bool {
		return (bool) preg_match( self::ID_PATTERN, $id );
	}

	public static function isRunning( ?array $run ): bool {
		return $run !== null && $run['status'] === self::RUNNING;
	}

	/**
	 * What the status of the current run means for the rest of the state:
	 * - an import done, or stopped (or failed) with products written, is the last import, undoable when it wrote
	 *   something; a done import completes the NCM import;
	 * - a finished undo closes the import it undid.
	 *
	 * @param array $state FiscalImportRunRepository::state().
	 */
	public static function settle( array $state ): array {
		$run = $state['run'];

		if ( $run === null || in_array( $run['status'], [ self::RUNNING, self::READY ], true ) ) {
			return $state;
		}

		if ( $run['mode'] === self::MODE_APPLY && ( $run['status'] === self::DONE || $run['counts']['writes'] > 0 ) ) {
			$run['undoable'] = $run['counts']['writes'] > 0;
			$state['run'] = $run;
			$state['last'] = $run;

			if ( $run['status'] === self::DONE ) {
				$state['completed']['ncm'] = $run['id'];
			}
		}

		if ( $run['mode'] === self::MODE_UNDO && $run['status'] === self::DONE && $state['last'] !== null && $state['last']['id'] === $run['parentId'] ) {
			$state['last']['undoable'] = false;
		}

		return $state;
	}

	/**
	 * A stored run with every key and type in place; null when it is not a run.
	 *
	 * @param mixed $run
	 */
	public static function normalize( $run ): ?array {
		if ( ! is_array( $run ) || ! isset( $run['id'] ) || ! is_string( $run['id'] ) || ! self::isValidId( $run['id'] ) ) {
			return null;
		}

		$targets = [];

		foreach ( isset( $run['targets'] ) && is_array( $run['targets'] ) ? $run['targets'] : [] as $ncm => $count ) {
			$targets[ (string) $ncm ] = (int) $count;
		}

		return [
			'id' => $run['id'],
			'mode' => in_array( $run['mode'] ?? '', self::MODES, true ) ? $run['mode'] : self::MODE_PREVIEW,
			'status' => in_array( $run['status'] ?? '', self::STATUSES, true ) ? $run['status'] : self::FAILED,
			'choices' => FiscalImportChoices::fromArray( isset( $run['choices'] ) && is_array( $run['choices'] ) ? $run['choices'] : [], FiscalImportChoices::defaults( false ) )->toArray(),
			'processed' => max( 0, (int) ( $run['processed'] ?? 0 ) ),
			'total' => max( 0, (int) ( $run['total'] ?? 0 ) ),
			'counts' => FiscalImportCounts::normalize( $run['counts'] ?? [] ),
			'startedAt' => max( 0, (int) ( $run['startedAt'] ?? 0 ) ),
			'finishedAt' => isset( $run['finishedAt'] ) ? (int) $run['finishedAt'] : null,
			'startedBy' => (string) ( $run['startedBy'] ?? '' ),
			'startedById' => max( 0, (int) ( $run['startedById'] ?? 0 ) ),
			'error' => isset( $run['error'] ) && is_string( $run['error'] ) ? $run['error'] : null,
			'parentId' => isset( $run['parentId'] ) && is_string( $run['parentId'] ) ? $run['parentId'] : null,
			'undoable' => ! empty( $run['undoable'] ),
			'pushedFilled' => max( 0, (int) ( $run['pushedFilled'] ?? 0 ) ),
			'coverageBefore' => isset( $run['coverageBefore'] ) ? max( 0, (int) $run['coverageBefore'] ) : null,
			'cursor' => max( 0, (int) ( $run['cursor'] ?? 0 ) ),
			'heartbeat' => max( 0, (int) ( $run['heartbeat'] ?? 0 ) ),
			'doneNotice' => ! empty( $run['doneNotice'] ),
			'phase' => ( $run['phase'] ?? '' ) === self::PHASE_VERIFY ? self::PHASE_VERIFY : self::PHASE_PLAN,
			'targets' => $targets,
		];
	}

	/**
	 * The run as the admin app sees it (TS FiscalImportRun).
	 */
	public static function toPublic( array $run ): array {
		return [
			'id' => $run['id'],
			'mode' => $run['mode'],
			'status' => $run['status'],
			'choices' => $run['choices'],
			'processed' => $run['processed'],
			'total' => $run['total'],
			'counts' => $run['counts'],
			'startedAt' => gmdate( 'c', $run['startedAt'] ),
			'finishedAt' => $run['finishedAt'] !== null ? gmdate( 'c', $run['finishedAt'] ) : null,
			'startedBy' => $run['startedBy'],
			'error' => $run['error'],
			'parentId' => $run['parentId'],
			'undoable' => $run['mode'] === self::MODE_APPLY && $run['undoable'],
			'pushedFilled' => $run['pushedFilled'],
			'coverageBefore' => $run['coverageBefore'],
		];
	}
}
