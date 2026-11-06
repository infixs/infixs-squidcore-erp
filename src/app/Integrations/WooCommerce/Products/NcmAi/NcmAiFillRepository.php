<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

use Infixs\SquidCore\Support\FreshOption;

defined( 'ABSPATH' ) || exit;

/**
 * Where the AI filling of the NCM keeps its run (the current one or the last one) and the lists of its products: the
 * ones that got an NCM and the ones that did not, with the reason.
 *
 * The batches, the browser and the bulk action write it from different requests, so the batches read it straight from
 * the table (FreshOption) and every change re-reads it right before writing.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class NcmAiFillRepository {

	/**
	 * The run (NcmAiFillRun), not autoloaded.
	 */
	public const OPTION = 'infixs_squidcore_ncm_ai_fill';

	/**
	 * Lists of the run: {runId, filled: rows, failed: rows}, not autoloaded.
	 */
	public const ITEMS_OPTION = 'infixs_squidcore_ncm_ai_fill_items';

	/**
	 * Lock held by the batch that is working (OptionLock). A batch waits for the AI, so it may take minutes.
	 */
	public const LOCK = 'infixs_squidcore_ncm_ai_fill_lock';

	public const LOCK_TTL = 600;

	/**
	 * Rows kept per list.
	 */
	public const ITEMS_LIMIT = 1000;

	public const FILLED = 'filled';

	public const FAILED = 'failed';

	public const LISTS = [ self::FILLED, self::FAILED ];

	/**
	 * The run read straight from the table, for the batches and the actions.
	 */
	public function run(): ?array {
		return NcmAiFillRun::normalize( FreshOption::get( self::OPTION ) );
	}

	/**
	 * The run through the options cache, for the screens, which read it several times per page.
	 */
	public function snapshot(): ?array {
		return NcmAiFillRun::normalize( get_option( self::OPTION, null ) );
	}

	/**
	 * Changes the run read right now.
	 *
	 * @param callable $change Receives the run (null when none) and returns it changed (null leaves it as it was).
	 *
	 * @return array|null The run saved.
	 */
	public function update( callable $change ): ?array {
		$current = $this->run();
		$run = $change( $current );

		if ( ! is_array( $run ) ) {
			return $current;
		}

		$this->save( self::OPTION, $run );

		return $run;
	}

	/**
	 * @return array{runId: string, filled: array[], failed: array[]}
	 */
	public function items(): array {
		$stored = FreshOption::get( self::ITEMS_OPTION );
		$stored = is_array( $stored ) ? $stored : [];
		$items = [ 'runId' => isset( $stored['runId'] ) ? (string) $stored['runId'] : '' ];

		foreach ( self::LISTS as $list ) {
			$items[ $list ] = isset( $stored[ $list ] ) && is_array( $stored[ $list ] ) ? array_values( array_filter( $stored[ $list ], 'is_array' ) ) : [];
		}

		return $items;
	}

	/**
	 * Empty lists for a new run: the ones of the previous run go.
	 */
	public function resetItems( string $runId ): void {
		$this->save( self::ITEMS_OPTION, [
			'runId' => $runId,
			self::FILLED => [],
			self::FAILED => [],
		] );
	}

	/**
	 * Adds rows to the lists of the run, up to ITEMS_LIMIT each. Ignored when the lists belong to another run.
	 *
	 * @param array<string, array[]> $rows List => rows.
	 */
	public function appendItems( string $runId, array $rows ): void {
		if ( ! array_filter( $rows ) ) {
			return;
		}

		$items = $this->items();

		if ( $items['runId'] !== $runId ) {
			return;
		}

		foreach ( self::LISTS as $list ) {
			$room = self::ITEMS_LIMIT - count( $items[ $list ] );
			$items[ $list ] = array_merge( $items[ $list ], array_slice( array_values( $rows[ $list ] ?? [] ), 0, max( 0, $room ) ) );
		}

		$this->save( self::ITEMS_OPTION, $items );
	}

	/**
	 * Saves an option of the filling, never autoloaded. The cached copy is dropped first: it may be older than the row,
	 * and update_option() skips a write equal to it.
	 *
	 * @param mixed $value
	 */
	private function save( string $option, $value ): void {
		wp_cache_delete( $option, 'options' );
		update_option( $option, $value, false );
	}
}
