<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCode;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmState;

defined( 'ABSPATH' ) || exit;

/**
 * Decides, for one product, what the import does with its NCM. Pure: the same input always gives the same decision,
 * and nothing is read from or written to the store.
 *
 * The NCM taken is the one WebmaniaBR put on the invoices of the product, in its own order: the variation NCM, then
 * the product NCM, then the NCM of its single category (several categories with different codes are left out) and,
 * only when chosen, the store-wide NCM. Like WebmaniaBR, the first non-empty value wins and is validated afterwards.
 * A variable product gets an NCM only when every variation ends with the same one. A valid NCM the product already
 * has is never replaced without the store owner choosing so.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
final class FiscalImportPlanner {

	public const ORIGIN_PRODUCT = 'product';

	public const ORIGIN_VARIATIONS = 'variations';

	public const ORIGIN_CATEGORY = 'category';

	public const ORIGIN_GLOBAL = 'global';

	/**
	 * Origin of a single variation in FiscalDecision::variations().
	 */
	public const ORIGIN_VARIATION = 'variation';

	/**
	 * The catch-all signal needs more written products than this.
	 */
	public const CATCH_ALL_MIN_WRITES = 20;

	/**
	 * Product types WooCommerce does not sell directly.
	 */
	private const LEFT_OUT_TYPES = [ 'grouped', 'external' ];

	/**
	 * Outcomes of the NCM lookup.
	 */
	private const FOUND = 'found';

	private const NONE = 'none';

	private const CATEGORIES_DIFFER = 'categories_differ';

	private const VARIATIONS_DIFFER = 'variations_differ';

	/**
	 * @param FiscalRecord        $record    Data of the other plugin.
	 * @param NcmState            $current   NCM of the product in the store now.
	 * @param FiscalImportChoices $choices   What the store owner chose to bring.
	 * @param string              $globalNcm Store-wide NCM of the other plugin, as typed.
	 */
	public function plan( FiscalRecord $record, NcmState $current, FiscalImportChoices $choices, string $globalNcm ): FiscalDecision {
		$now = $current->value();

		if ( in_array( $record->type(), self::LEFT_OUT_TYPES, true ) ) {
			return new FiscalDecision( FiscalDecision::SKIP, $now, '', null, '', $record->type() );
		}

		if ( $record->isService() ) {
			return new FiscalDecision( FiscalDecision::SKIP, $now, '', null, '', 'service' );
		}

		$found = $record->variations()
			? $this->variationsLookup( $record, $choices, $globalNcm )
			: $this->productLookup( $record, $choices, $globalNcm );

		if ( $found['outcome'] === self::CATEGORIES_DIFFER ) {
			return new FiscalDecision( FiscalDecision::SKIP, $now, '', null, '', self::CATEGORIES_DIFFER, false, false, [], $found['categories'] );
		}

		if ( $found['outcome'] === self::VARIATIONS_DIFFER ) {
			return new FiscalDecision( FiscalDecision::REVIEW, $now, '', null, '', self::VARIATIONS_DIFFER, false, false, $found['variations'] );
		}

		if ( $found['outcome'] === self::NONE ) {
			return $current->isValid()
				? new FiscalDecision( FiscalDecision::NOTHING, $now )
				: new FiscalDecision( FiscalDecision::MISSING, $now, '', null, '', 'no_source' );
		}

		return $this->decide( $found, $current, $choices );
	}

	/**
	 * The catch-all signal: one NCM going to half or more of the written products, when more than
	 * CATCH_ALL_MIN_WRITES were written.
	 *
	 * @param array<string|int, int> $targets NCM digits => products written with it.
	 * @param int                    $writes  Products written.
	 *
	 * @return array{ncm: string, count: int, of: int}|null The NCM formatted; null when no NCM dominates.
	 */
	public static function catchAll( array $targets, int $writes ): ?array {
		if ( $writes <= self::CATCH_ALL_MIN_WRITES ) {
			return null;
		}

		$top = null;
		$count = 0;

		foreach ( $targets as $ncm => $products ) {
			$ncm = (string) $ncm;

			if ( (int) $products > $count || ( (int) $products === $count && $top !== null && strcmp( $ncm, $top ) < 0 ) ) {
				$top = $ncm;
				$count = (int) $products;
			}
		}

		if ( $top === null || $count * 2 < $writes ) {
			return null;
		}

		return [
			'ncm' => NcmCode::format( $top ),
			'count' => $count,
			'of' => $writes,
		];
	}

	/**
	 * Decision for an NCM found in the other plugin.
	 *
	 * @param array $found {raw, origin, parentOverridden}
	 */
	private function decide( array $found, NcmState $current, FiscalImportChoices $choices ): FiscalDecision {
		$now = $current->value();
		$raw = $found['raw'];
		$digits = NcmCode::normalize( $raw );
		$problem = NcmCode::problem( $digits );
		$decision = function ( string $action, ?string $reason = null, bool $fixesInvalid = false ) use ( $now, $digits, $found, $raw ): FiscalDecision {
			return new FiscalDecision( $action, $now, $digits, $found['origin'], $raw, $reason, $fixesInvalid, $found['parentOverridden'] );
		};

		if ( $problem !== null ) {
			if ( $current->isValid() ) {
				return $decision( FiscalDecision::NOTHING );
			}

			// Text without a digit ("null") has 0 digits: the same advice as a short code.
			return $decision( FiscalDecision::REVIEW, 'ncm_' . ( $problem === NcmCode::PROBLEM_EMPTY ? NcmCode::PROBLEM_LENGTH : $problem ) );
		}

		if ( $now === '' ) {
			return $decision( FiscalDecision::FILL );
		}

		if ( ! $current->isValid() ) {
			return $decision( FiscalDecision::FILL, null, true );
		}

		if ( $digits === $now ) {
			return $decision( FiscalDecision::SAME );
		}

		// Only the NCM left by Correios Automático while it is off is hidden: while active, its field was the visible
		// one (and the SquidCore field shows it), so it is a conflict like any other.
		if ( $current->fromCorreios() && ! $current->correiosActive() ) {
			return $decision( $choices->hidden() === FiscalImportChoices::HIDDEN_WEBMANIA ? FiscalDecision::HIDDEN_REPLACE : FiscalDecision::HIDDEN_KEEP );
		}

		return $decision( $choices->overwrite() ? FiscalDecision::REPLACE : FiscalDecision::KEEP );
	}

	/**
	 * NCM of a product without variations: its own, else its single category's, else the store-wide one when chosen
	 * and valid.
	 *
	 * @return array{outcome: string, raw: string, origin: string|null, parentOverridden: bool, categories: array}
	 */
	private function productLookup( FiscalRecord $record, FiscalImportChoices $choices, string $globalNcm ): array {
		$own = trim( $record->productNcm() );

		if ( $own !== '' ) {
			return $this->found( $own, self::ORIGIN_PRODUCT );
		}

		$categories = array_filter( $record->categories(), function ( array $category ): bool {
			return trim( $category['ncm'] ) !== '';
		} );

		if ( $choices->useCategory() && $categories ) {
			$codes = array_unique( array_map( function ( array $category ): string {
				return NcmCode::normalize( $category['ncm'] );
			}, $categories ) );

			if ( count( $codes ) > 1 ) {
				$outcome = $this->outcome( self::CATEGORIES_DIFFER );
				$outcome['categories'] = $this->categoryList( $categories );

				return $outcome;
			}

			return $this->found( trim( reset( $categories )['ncm'] ), self::ORIGIN_CATEGORY );
		}

		$global = trim( $globalNcm );

		if ( $choices->useGlobal() && NcmCode::isValid( NcmCode::normalize( $global ) ) ) {
			return $this->found( $global, self::ORIGIN_GLOBAL );
		}

		return $this->outcome( self::NONE );
	}

	/**
	 * NCM of a variable product: each variation takes its own NCM or, without one, the product lookup; the product
	 * gets an NCM only when they all end with the same code.
	 *
	 * @return array{outcome: string, raw: string, origin: string|null, parentOverridden: bool, variations: array, categories: array}
	 */
	private function variationsLookup( FiscalRecord $record, FiscalImportChoices $choices, string $globalNcm ): array {
		$fallback = null;
		$rows = [];
		$own_raw = null;
		$all_own = true;

		foreach ( $record->variations() as $id => $variation ) {
			$raw = trim( $variation['ncm'] );
			$origin = self::ORIGIN_VARIATION;

			if ( $raw === '' ) {
				$all_own = false;
				$fallback = $fallback ?? $this->productLookup( $record, $choices, $globalNcm );

				if ( $fallback['outcome'] === self::CATEGORIES_DIFFER ) {
					return $fallback;
				}

				$raw = $fallback['raw'];
				$origin = $fallback['origin'];
			} elseif ( $own_raw === null ) {
				$own_raw = $raw;
			}

			$rows[] = [
				'id' => (int) $id,
				'name' => $variation['name'],
				'raw' => $raw,
				'origin' => $origin,
			];
		}

		$codes = array_unique( array_map( function ( array $row ): string {
			return NcmCode::normalize( $row['raw'] );
		}, $rows ) );

		if ( count( $codes ) > 1 ) {
			$outcome = $this->outcome( self::VARIATIONS_DIFFER );
			$outcome['variations'] = array_map( function ( array $row ): array {
				return [
					'id' => $row['id'],
					'name' => $row['name'],
					'ncm' => self::display( $row['raw'] ),
					'origin' => $row['origin'],
				];
			}, $rows );

			return $outcome;
		}

		if ( $own_raw !== null ) {
			$found = $this->found( $own_raw, self::ORIGIN_VARIATIONS );
			$code = NcmCode::normalize( $own_raw );
			$parent = NcmCode::normalize( $record->productNcm() );
			$found['parentOverridden'] = $all_own && NcmCode::isValid( $parent ) && $parent !== $code;

			return $found;
		}

		// No variation has its own NCM: all of them took the product lookup.
		return $fallback ?? $this->outcome( self::NONE );
	}

	/**
	 * @return array{outcome: string, raw: string, origin: string|null, parentOverridden: bool, variations: array, categories: array}
	 */
	private function found( string $raw, string $origin ): array {
		$found = $this->outcome( self::FOUND );
		$found['raw'] = $raw;
		$found['origin'] = $origin;

		return $found;
	}

	/**
	 * @return array{outcome: string, raw: string, origin: string|null, parentOverridden: bool, variations: array, categories: array}
	 */
	private function outcome( string $outcome ): array {
		return [
			'outcome' => $outcome,
			'raw' => '',
			'origin' => null,
			'parentOverridden' => false,
			'variations' => [],
			'categories' => [],
		];
	}

	/**
	 * @param array<int, array{name: string, ncm: string}> $categories
	 *
	 * @return array<int, array{id: int, name: string, ncm: string}>
	 */
	private function categoryList( array $categories ): array {
		$list = [];

		foreach ( $categories as $id => $category ) {
			$list[] = [
				'id' => (int) $id,
				'name' => $category['name'],
				'ncm' => self::display( $category['ncm'] ),
			];
		}

		return $list;
	}

	/**
	 * An NCM to show: formatted when valid, as typed otherwise.
	 */
	private static function display( string $raw ): string {
		$digits = NcmCode::normalize( $raw );

		return NcmCode::isValid( $digits ) ? NcmCode::format( $digits ) : trim( $raw );
	}
}
