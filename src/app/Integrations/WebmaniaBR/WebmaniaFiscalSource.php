<?php

namespace Infixs\SquidCore\Integrations\WebmaniaBR;

use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalDataSource;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Fiscal data WebmaniaBR kept for the products: the only reader of its product, variation and category keys.
 *
 * It reads post metas, term metas and the post table, never the plugin itself, so it works with WebmaniaBR active,
 * deactivated or deleted. The batches prime the caches, so reading a product of the batch costs no query.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class WebmaniaFiscalSource implements FiscalDataSource {

	/**
	 * Statuses of the variations WooCommerce lists for a variable product (private = disabled).
	 */
	private const VARIATION_STATUSES = [ 'publish', 'private' ];

	/**
	 * @var WebmaniaBR
	 */
	protected $webmania;

	/**
	 * Variations of the products of the last batch: product id => [variation id => title], in menu order.
	 *
	 * @var array<int, array<int, string>>
	 */
	private $variations = [];

	public function __construct( WebmaniaBR $webmania ) {
		$this->webmania = $webmania;
	}

	public function nextBatch( int $afterId, int $maxPosts ): array {
		global $wpdb;

		$max_posts = max( 1, $maxPosts );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- background batch by id; the variations are counted through the post_parent index.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID AS id,
				(SELECT COUNT(*) FROM {$wpdb->posts} v WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' AND v.post_status IN ('publish', 'private')) AS variations
			FROM {$wpdb->posts} p
			WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft') AND p.ID > %d
			ORDER BY p.ID ASC
			LIMIT %d",
			$afterId,
			$max_posts
		), ARRAY_A );

		$ids = [];
		$posts = 0;

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$weight = 1 + (int) $row['variations'];

			if ( $ids && $posts + $weight > $max_posts ) {
				break;
			}

			$ids[] = (int) $row['id'];
			$posts += $weight;
		}

		$this->prime( $ids );

		return $ids;
	}

	public function countProducts(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- progress total of a background run.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash', 'auto-draft')" );
	}

	public function read( int $productId ): ?FiscalRecord {
		$post = get_post( $productId );

		if ( ! $post instanceof \WP_Post || $post->post_type !== 'product' ) {
			return null;
		}

		$type = (string) \WC_Product_Factory::get_product_type( $productId );
		$variations = [];

		if ( is_a( \WC_Product_Factory::get_product_classname( $productId, $type ), 'WC_Product_Variable', true ) ) {
			foreach ( $this->variationsOf( $productId ) as $variation_id => $title ) {
				$variations[ $variation_id ] = [
					'name' => $title,
					'ncm' => $this->meta( $variation_id, WebmaniaBR::VARIATION_NCM_META ),
				];
			}
		}

		return new FiscalRecord(
			$productId,
			$type,
			(string) $post->post_title,
			$this->meta( $productId, '_virtual' ) === 'yes',
			$this->meta( $productId, WebmaniaBR::PRODUCT_NCM_META ),
			$variations,
			$this->categories( $productId ),
			$this->meta( $productId, WebmaniaBR::TYPE_META ) === '2',
			$this->meta( $productId, WebmaniaBR::IGNORE_META ) === '1'
		);
	}

	public function globalNcm(): string {
		return $this->webmania->globalNcm();
	}

	/**
	 * Loads the posts, metas and terms of the products and of their variations, and the metas of their categories.
	 *
	 * @param int[] $ids Parent product ids.
	 */
	private function prime( array $ids ): void {
		$this->variations = [];

		if ( ! $ids ) {
			return;
		}

		_prime_post_caches( $ids, true, true );
		update_meta_cache( 'post', $ids );
		update_object_term_cache( $ids, 'product' );

		foreach ( $ids as $id ) {
			$this->variations[ $id ] = [];
		}

		foreach ( $this->queryVariations( $ids ) as $row ) {
			$this->variations[ (int) $row['parent'] ][ (int) $row['id'] ] = (string) $row['title'];
		}

		$variation_ids = [];

		foreach ( $this->variations as $children ) {
			$variation_ids = array_merge( $variation_ids, array_keys( $children ) );
		}

		if ( $variation_ids ) {
			update_meta_cache( 'post', $variation_ids );
		}

		$term_ids = [];

		foreach ( $ids as $id ) {
			$terms = get_object_term_cache( $id, 'product_cat' );
			$term_ids = array_merge( $term_ids, is_array( $terms ) ? wp_list_pluck( $terms, 'term_id' ) : [] );
		}

		if ( $term_ids ) {
			update_termmeta_cache( array_values( array_unique( array_map( 'intval', $term_ids ) ) ) );
		}
	}

	/**
	 * Variations WooCommerce lists for the product (published and disabled), in menu order: from the batch, or read
	 * now for a product outside it. Read here rather than through WooCommerce, which would keep a transient per
	 * product.
	 *
	 * @return array<int, string> Variation id => title.
	 */
	private function variationsOf( int $productId ): array {
		if ( isset( $this->variations[ $productId ] ) ) {
			return $this->variations[ $productId ];
		}

		$children = [];

		foreach ( $this->queryVariations( [ $productId ] ) as $row ) {
			$children[ (int) $row['id'] ] = (string) $row['title'];
		}

		return $children;
	}

	/**
	 * @param int[] $parentIds
	 *
	 * @return array<int, array{id: string, parent: string, title: string}>
	 */
	private function queryVariations( array $parentIds ): array {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $parentIds ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one query per batch through the post_parent index; $placeholders is a list of %d built above.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID AS id, post_parent AS parent, post_title AS title FROM {$wpdb->posts}
			WHERE post_type = 'product_variation' AND post_status IN ('publish', 'private') AND post_parent IN ({$placeholders})
			ORDER BY post_parent ASC, menu_order ASC, ID ASC",
			$parentIds
		), ARRAY_A );
		// phpcs:enable

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Direct categories of the product with a WebmaniaBR NCM, in the order WordPress lists them.
	 *
	 * @return array<int, array{name: string, ncm: string}>
	 */
	private function categories( int $productId ): array {
		$terms = get_the_terms( $productId, 'product_cat' );
		$categories = [];

		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$ncm = get_term_meta( $term->term_id, WebmaniaBR::CATEGORY_NCM_META, true );
			$ncm = is_scalar( $ncm ) ? trim( (string) $ncm ) : '';

			if ( $ncm !== '' ) {
				$categories[ (int) $term->term_id ] = [
					'name' => (string) $term->name,
					'ncm' => $ncm,
				];
			}
		}

		return $categories;
	}

	private function meta( int $postId, string $key ): string {
		$value = get_post_meta( $postId, $key, true );

		return is_scalar( $value ) ? (string) $value : '';
	}
}
