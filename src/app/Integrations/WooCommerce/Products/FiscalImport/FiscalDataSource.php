<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

defined( 'ABSPATH' ) || exit;

/**
 * Where the import reads the fiscal data another invoice plugin kept for the products.
 *
 * An interface because it crosses into the storage of a third-party plugin, which has no contract and whose keys may
 * change: the import engine and the planner only know FiscalRecord.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
interface FiscalDataSource {

	/**
	 * Next parent products to go through, by id: post type `product`, any status but trash and auto-draft, id above
	 * $afterId, ascending. Their posts (the product plus its variations) add up to at most $maxPosts, but at least one
	 * product comes back while any is left. Primes the post, meta and term caches of the products and variations.
	 *
	 * @return int[] Empty when no product is left.
	 */
	public function nextBatch( int $afterId, int $maxPosts ): array;

	/**
	 * Number of products in the universe of nextBatch(), for the progress.
	 */
	public function countProducts(): int;

	/**
	 * Fiscal data of a parent product, null when the product is gone (or is a variation).
	 */
	public function read( int $productId ): ?FiscalRecord;

	/**
	 * Store-wide NCM, as typed ('' when unset).
	 */
	public function globalNcm(): string;
}
