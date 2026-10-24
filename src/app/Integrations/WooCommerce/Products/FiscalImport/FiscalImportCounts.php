<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

defined( 'ABSPATH' ) || exit;

/**
 * Totals of a scan or a run (TS FiscalImportCounts): one counter per planner action, plus what the written products
 * have in common.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
final class FiscalImportCounts {

	private const ORIGINS = [
		FiscalImportPlanner::ORIGIN_PRODUCT,
		FiscalImportPlanner::ORIGIN_VARIATIONS,
		FiscalImportPlanner::ORIGIN_CATEGORY,
		FiscalImportPlanner::ORIGIN_GLOBAL,
	];

	/**
	 * Counter of each planner action.
	 */
	private const ACTIONS = [
		FiscalDecision::FILL => 'fill',
		FiscalDecision::SAME => 'same',
		FiscalDecision::KEEP => 'keep',
		FiscalDecision::REPLACE => 'replace',
		FiscalDecision::HIDDEN_REPLACE => 'hiddenReplace',
		FiscalDecision::REVIEW => 'review',
		FiscalDecision::SKIP => 'skipped',
		FiscalDecision::MISSING => 'missing',
		FiscalDecision::NOTHING => 'nothing',
	];

	private const NUMBERS = [
		'writes', 'fill', 'fillFixesInvalid', 'same', 'keep', 'replace', 'hidden', 'hiddenReplace', 'review', 'skipped',
		'missing', 'nothing', 'ignoredInWebmania', 'virtual', 'keptEdited', 'changedDuring',
	];

	/**
	 * @return array Every counter at zero.
	 */
	public static function blank(): array {
		return self::normalize( [] );
	}

	/**
	 * @param mixed $counts Stored counts.
	 */
	public static function normalize( $counts ): array {
		$counts = is_array( $counts ) ? $counts : [];
		$normalized = [];

		foreach ( self::NUMBERS as $key ) {
			$normalized[ $key ] = max( 0, (int) ( $counts[ $key ] ?? 0 ) );
		}

		$normalized['byOrigin'] = [];

		foreach ( self::ORIGINS as $origin ) {
			$normalized['byOrigin'][ $origin ] = max( 0, (int) ( $counts['byOrigin'][ $origin ] ?? 0 ) );
		}

		$catch_all = $counts['catchAll'] ?? null;
		$normalized['catchAll'] = is_array( $catch_all ) && isset( $catch_all['ncm'], $catch_all['count'], $catch_all['of'] )
			? [ 'ncm' => (string) $catch_all['ncm'], 'count' => (int) $catch_all['count'], 'of' => (int) $catch_all['of'] ]
			: null;

		return $normalized;
	}

	/**
	 * Counts the decision on one product.
	 *
	 * @param bool $written The NCM was written (preview and scan: would be written).
	 */
	public static function add( array $counts, FiscalDecision $decision, FiscalRecord $record, bool $written ): array {
		$action = $decision->action();

		if ( isset( self::ACTIONS[ $action ] ) ) {
			++$counts[ self::ACTIONS[ $action ] ];
		}

		if ( $action === FiscalDecision::HIDDEN_REPLACE || $action === FiscalDecision::HIDDEN_KEEP ) {
			++$counts['hidden'];
		}

		if ( $action === FiscalDecision::FILL && $decision->fixesInvalid() ) {
			++$counts['fillFixesInvalid'];
		}

		if ( ! $written ) {
			return $counts;
		}

		++$counts['writes'];

		if ( isset( $counts['byOrigin'][ (string) $decision->origin() ] ) ) {
			++$counts['byOrigin'][ (string) $decision->origin() ];
		}

		if ( $record->ignoredInWebmania() ) {
			++$counts['ignoredInWebmania'];
		}

		if ( $record->isVirtual() ) {
			++$counts['virtual'];
		}

		return $counts;
	}

	/**
	 * Sets the catch-all signal from the NCM written so far.
	 *
	 * @param array<string, int> $targets NCM digits => products written with it.
	 */
	public static function withCatchAll( array $counts, array $targets ): array {
		$counts['catchAll'] = FiscalImportPlanner::catchAll( $targets, $counts['writes'] );

		return $counts;
	}

	/**
	 * Real number of products of a list (the stored list keeps the first FiscalImportItems::LIMIT).
	 */
	public static function forList( array $counts, string $type ): int {
		switch ( $type ) {
			case FiscalDecision::LIST_CONFLICT:
				return $counts['keep'] + $counts['replace'];
			case FiscalDecision::LIST_HIDDEN:
				return $counts['hidden'];
			case FiscalDecision::LIST_REVIEW:
				return $counts['review'];
			case FiscalDecision::LIST_SKIPPED:
				return $counts['skipped'];
			case FiscalDecision::LIST_MISSING:
				return $counts['missing'];
			case FiscalImportItems::CHANGED:
				return $counts['changedDuring'];
		}

		return 0;
	}
}
