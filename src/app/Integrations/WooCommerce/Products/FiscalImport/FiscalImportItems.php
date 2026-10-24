<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCode;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of the lists of a run (TS FiscalImportItem): the products kept, reviewed, left out, still without NCM, and
 * the ones saved while the import ran.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
final class FiscalImportItems {

	/**
	 * List of the products saved during an apply.
	 */
	public const CHANGED = 'changed';

	public const TYPES = [
		FiscalDecision::LIST_CONFLICT,
		FiscalDecision::LIST_HIDDEN,
		FiscalDecision::LIST_REVIEW,
		FiscalDecision::LIST_SKIPPED,
		FiscalDecision::LIST_MISSING,
		self::CHANGED,
	];

	/**
	 * Rows kept per list; the counts have the real numbers.
	 */
	public const LIMIT = 500;

	/**
	 * Variations kept in a row whose variations differ.
	 */
	private const VARIATIONS_LIMIT = 50;

	private const EDITED_DURING_IMPORT = 'edited_during_import';

	/**
	 * @param bool $replaced The import writes (or would write) the NCM of the other plugin.
	 */
	public static function fromDecision( FiscalRecord $record, FiscalDecision $decision, bool $replaced ): array {
		return self::row(
			$record->productId(),
			$record->name(),
			NcmCode::format( $decision->current() ),
			self::otherPluginNcm( $decision ),
			$decision->origin(),
			$decision->reason(),
			$replaced,
			array_slice( $decision->variations(), 0, self::VARIATIONS_LIMIT ),
			$decision->categories()
		);
	}

	/**
	 * A product written by the import and saved with another NCM before the import ended.
	 *
	 * @param string $current Its SquidCore NCM now, as stored.
	 * @param array  $entry   Its FiscalImportJournal entry.
	 */
	public static function changed( int $productId, string $name, string $current, array $entry ): array {
		return self::row( $productId, $name, NcmCode::format( NcmCode::normalize( $current ) ), NcmCode::format( $entry['after'] ), $entry['origin'], self::EDITED_DURING_IMPORT, false, [], [] );
	}

	/**
	 * The stored row with the link to the product editor.
	 */
	public static function present( array $item ): array {
		$item['editUrl'] = admin_url( 'post.php?post=' . (int) $item['productId'] . '&action=edit' );

		return $item;
	}

	/**
	 * The NCM the other plugin uses: formatted when valid, as typed when not, blank when none.
	 */
	private static function otherPluginNcm( FiscalDecision $decision ): string {
		$target = $decision->target();

		return $target !== '' && NcmCode::isValid( $target ) ? NcmCode::format( $target ) : trim( $decision->source() );
	}

	private static function row( int $productId, string $name, string $current, string $webmania, ?string $origin, ?string $reason, bool $replaced, array $variations, array $categories ): array {
		return [
			'productId' => $productId,
			'name' => $name,
			'current' => $current,
			'webmania' => $webmania,
			'origin' => $origin,
			'reason' => $reason,
			'replaced' => $replaced,
			'variations' => array_values( $variations ),
			'categories' => array_values( $categories ),
		];
	}
}
