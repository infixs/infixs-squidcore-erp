<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCode;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmState;

defined( 'ABSPATH' ) || exit;

/**
 * History of the import on each product: one entry per product, from the latest run that wrote it, with the metas
 * before the write, so the run can be checked, pushed and undone product by product. Kept on the product, so it
 * scales with the catalog.
 *
 * An entry names its product: a copy that another product got (WooCommerce "Duplicate" leaves it out, other tools may
 * not) is not history of that product and is ignored.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportJournal {

	/**
	 * {run, product, before: {own, correios}, after, source, origin, at, pushedAt}.
	 */
	public const META_KEY = '_infixs_squidcore_fiscal_import';

	/**
	 * A duplicated product does not take the history of the original.
	 */
	public function register(): void {
		add_filter( 'woocommerce_duplicate_product_exclude_meta', [ $this, 'excludeFromDuplicate' ] );
	}

	/**
	 * @param mixed $keys Meta keys WooCommerce leaves out of a duplicate.
	 *
	 * @return array
	 */
	public function excludeFromDuplicate( $keys ) {
		$keys = is_array( $keys ) ? $keys : [];
		$keys[] = self::META_KEY;

		return $keys;
	}

	/**
	 * Records the write of a run on the product. The caller saves the product metas (save_meta_data()).
	 *
	 * @param NcmState    $before NCM of the product before the write.
	 * @param string      $after  SquidCore NCM the write leaves (digits).
	 * @param string      $source The NCM as typed in the other plugin.
	 * @param string|null $origin product, variations, category or global.
	 */
	public function record( \WC_Product $product, string $runId, NcmState $before, string $after, string $source, ?string $origin ): void {
		$product->update_meta_data( self::META_KEY, [
			'run' => $runId,
			'product' => $product->get_id(),
			'before' => [
				'own' => $before->own(),
				'correios' => $before->correios(),
			],
			'after' => $after,
			'source' => $source,
			'origin' => $origin,
			'at' => time(),
			'pushedAt' => null,
		] );
	}

	/**
	 * The entry of the product; null when it has none, or only the copy of another product's.
	 *
	 * @return array{run: string, before: array{own: string|null, correios: string|null}, after: string, source: string, origin: string|null, at: int, pushedAt: int|null}|null
	 */
	public function entry( int $productId ): ?array {
		$entry = get_post_meta( $productId, self::META_KEY, true );

		if ( ! is_array( $entry ) || ! isset( $entry['run'] ) || ! is_string( $entry['run'] ) || (int) ( $entry['product'] ?? 0 ) !== $productId ) {
			return null;
		}

		$before = isset( $entry['before'] ) && is_array( $entry['before'] ) ? $entry['before'] : [];

		return [
			'run' => $entry['run'],
			'before' => [
				'own' => isset( $before['own'] ) && is_scalar( $before['own'] ) ? (string) $before['own'] : null,
				'correios' => isset( $before['correios'] ) && is_scalar( $before['correios'] ) ? (string) $before['correios'] : null,
			],
			'after' => (string) ( $entry['after'] ?? '' ),
			'source' => (string) ( $entry['source'] ?? '' ),
			'origin' => isset( $entry['origin'] ) && is_string( $entry['origin'] ) ? $entry['origin'] : null,
			'at' => (int) ( $entry['at'] ?? 0 ),
			'pushedAt' => isset( $entry['pushedAt'] ) ? (int) $entry['pushedAt'] : null,
		];
	}

	/**
	 * Notes when the product was queued to SquidCore.
	 */
	public function markPushed( int $productId, int $at ): void {
		$entry = $this->entry( $productId );

		if ( $entry === null ) {
			return;
		}

		$entry['pushedAt'] = $at;
		$entry['product'] = $productId;
		update_post_meta( $productId, self::META_KEY, $entry );
	}

	/**
	 * The write gave an NCM to a product that had none, or an invalid one.
	 */
	public static function wasFill( array $entry ): bool {
		$before = $entry['before']['own'] ?? $entry['before']['correios'] ?? '';

		return ! NcmCode::isValid( NcmCode::normalize( (string) $before ) );
	}

	/**
	 * Products whose entry belongs to the run, by id, after $afterId.
	 *
	 * @return int[]
	 */
	public function productIds( string $runId, int $afterId, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- background batch by id over the rows of one meta key.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d AND meta_value LIKE %s ORDER BY post_id ASC LIMIT %d",
			self::META_KEY,
			$afterId,
			$this->runPattern( $runId ),
			max( 1, $limit )
		) );

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * Products whose entry was not queued to SquidCore yet (pushedAt null), whatever run wrote it, by id, after
	 * $afterId.
	 *
	 * @return int[]
	 */
	public function unpushedIds( int $afterId, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- background batch by id over the rows of one meta key.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d AND meta_value LIKE %s ORDER BY post_id ASC LIMIT %d",
			self::META_KEY,
			$afterId,
			'%' . $wpdb->esc_like( '"pushedAt";N;' ) . '%',
			max( 1, $limit )
		) );

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * Number of products whose entry belongs to the run.
	 */
	public function count( string $runId ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- progress total of an undo, over the rows of one meta key.
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
			self::META_KEY,
			$this->runPattern( $runId )
		) );
	}

	/**
	 * The run id as it appears in the serialized entry (its format never appears in an NCM).
	 */
	private function runPattern( string $runId ): string {
		global $wpdb;

		return '%' . $wpdb->esc_like( '"' . $runId . '"' ) . '%';
	}
}
