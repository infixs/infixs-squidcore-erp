<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Support\FreshOption;

defined( 'ABSPATH' ) || exit;

/**
 * Where the import keeps its state: the current and the last run, the background scan, the push to SquidCore, the
 * lists of the most recent run, the scan result and the flag of a push waiting for the connection.
 *
 * The state is read straight from the table (FreshOption), since the batches, the browser and the jobs write it from
 * different requests, and every change re-reads it right before writing, so each writer only replaces its own part.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportRunRepository {

	/**
	 * State: {version, run, last, completed: {ncm}, scan, push}.
	 */
	public const OPTION = 'infixs_squidcore_fiscal_import';

	/**
	 * Lists of the most recent run: {runId, lists: {type: rows}}.
	 */
	public const ITEMS_OPTION = 'infixs_squidcore_fiscal_import_items';

	/**
	 * Run whose products wait for the connection (or the catalog sync) to go to SquidCore. Autoloaded: the admin
	 * checks it on every page while it exists.
	 */
	public const PUSH_PENDING_OPTION = 'infixs_squidcore_fiscal_push_pending';

	/**
	 * Result of the background scan (FiscalImportScan, finishedAt as unix time).
	 */
	public const SCAN_TRANSIENT = 'infixs_squidcore_fiscal_import_scan';

	public const SCAN_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Lock held by the batch that is writing the state (OptionLock).
	 */
	public const LOCK = 'infixs_squidcore_fiscal_import_lock';

	public const LOCK_TTL = 300;

	private const VERSION = 1;

	/**
	 * The state read straight from the table, for the batches and the jobs.
	 *
	 * @return array{version: int, run: array|null, last: array|null, completed: array{ncm: string|null}, scan: array|null, push: array|null}
	 */
	public function state(): array {
		return $this->normalize( FreshOption::get( self::OPTION ) );
	}

	/**
	 * The state through the options cache (one query per request at most), for the screens and the notices, which
	 * read it several times per page.
	 *
	 * @return array{version: int, run: array|null, last: array|null, completed: array{ncm: string|null}, scan: array|null, push: array|null}
	 */
	public function snapshot(): array {
		return $this->normalize( get_option( self::OPTION, null ) );
	}

	/**
	 * @param mixed $stored
	 */
	private function normalize( $stored ): array {
		$stored = is_array( $stored ) ? $stored : [];
		$completed = isset( $stored['completed'] ) && is_array( $stored['completed'] ) ? $stored['completed'] : [];

		return [
			'version' => self::VERSION,
			'run' => FiscalImportRun::normalize( $stored['run'] ?? null ),
			'last' => FiscalImportRun::normalize( $stored['last'] ?? null ),
			'completed' => [
				'ncm' => isset( $completed['ncm'] ) && is_string( $completed['ncm'] ) ? $completed['ncm'] : null,
			],
			'scan' => isset( $stored['scan'] ) && is_array( $stored['scan'] ) ? $stored['scan'] : null,
			'push' => isset( $stored['push'] ) && is_array( $stored['push'] ) ? $stored['push'] : null,
		];
	}

	/**
	 * Changes the state read right now.
	 *
	 * @param callable $change Receives the state and returns it changed.
	 *
	 * @return array The state saved.
	 */
	public function update( callable $change ): array {
		$state = $change( $this->state() );
		$this->save( self::OPTION, $state );

		return $state;
	}

	/**
	 * The current or the last run with this id, null when neither is.
	 */
	public function find( string $runId ): ?array {
		$state = $this->state();

		foreach ( [ $state['run'], $state['last'] ] as $run ) {
			if ( $run !== null && $run['id'] === $runId ) {
				return $run;
			}
		}

		return null;
	}

	/**
	 * @return array{runId: string, lists: array<string, array>}
	 */
	public function items(): array {
		$stored = FreshOption::get( self::ITEMS_OPTION );
		$stored = is_array( $stored ) ? $stored : [];
		$lists = [];

		foreach ( FiscalImportItems::TYPES as $type ) {
			$lists[ $type ] = isset( $stored['lists'][ $type ] ) && is_array( $stored['lists'][ $type ] ) ? array_values( $stored['lists'][ $type ] ) : [];
		}

		return [
			'runId' => isset( $stored['runId'] ) ? (string) $stored['runId'] : '',
			'lists' => $lists,
		];
	}

	/**
	 * Empty lists for a new run: the lists of the previous one are dropped.
	 */
	public function resetItems( string $runId ): void {
		$this->save( self::ITEMS_OPTION, [
			'runId' => $runId,
			'lists' => array_fill_keys( FiscalImportItems::TYPES, [] ),
		] );
	}

	/**
	 * Adds rows to the lists of the run, up to FiscalImportItems::LIMIT per list. Ignored when the run is no longer
	 * the most recent one.
	 *
	 * @param array<string, array> $lists Type => rows.
	 */
	public function appendItems( string $runId, array $lists ): void {
		if ( ! array_filter( $lists ) ) {
			return;
		}

		$items = $this->items();

		if ( $items['runId'] !== $runId ) {
			return;
		}

		foreach ( $lists as $type => $rows ) {
			if ( isset( $items['lists'][ $type ] ) ) {
				$room = FiscalImportItems::LIMIT - count( $items['lists'][ $type ] );
				$items['lists'][ $type ] = array_merge( $items['lists'][ $type ], array_slice( array_values( $rows ), 0, max( 0, $room ) ) );
			}
		}

		$this->save( self::ITEMS_OPTION, $items );
	}

	/**
	 * Result of the last background scan, null while there is none.
	 */
	public function scan(): ?array {
		$scan = get_transient( self::SCAN_TRANSIENT );

		return is_array( $scan ) ? $scan : null;
	}

	public function saveScan( array $scan ): void {
		set_transient( self::SCAN_TRANSIENT, $scan, self::SCAN_TTL );
	}

	public function forgetScan(): void {
		delete_transient( self::SCAN_TRANSIENT );
	}

	/**
	 * Run whose push waits for the connection or the catalog sync; null when none.
	 */
	public function pushPending(): ?string {
		$run_id = get_option( self::PUSH_PENDING_OPTION, '' );

		return is_string( $run_id ) && $run_id !== '' ? $run_id : null;
	}

	public function setPushPending( string $runId ): void {
		update_option( self::PUSH_PENDING_OPTION, $runId, true );
	}

	public function clearPushPending(): void {
		delete_option( self::PUSH_PENDING_OPTION );
	}

	/**
	 * Saves an option of the import, never autoloaded. The cached copy is dropped first: it may be older than the
	 * row, and update_option() skips a write equal to it.
	 *
	 * @param mixed $value
	 */
	private function save( string $option, $value ): void {
		wp_cache_delete( $option, 'options' );
		update_option( $option, $value, false );
	}
}
