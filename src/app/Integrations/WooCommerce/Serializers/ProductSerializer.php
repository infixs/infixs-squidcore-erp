<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Serializers;

use Infixs\SquidCore\Integrations\WooCommerce\Products\ProductNcmResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Serializes a WC_Product into the wc/v3 REST-like payload sent to SquidCore.
 *
 * Variations are embedded in the parent product payload.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class ProductSerializer {

	/**
	 * @var ProductNcmResolver
	 */
	protected $ncmResolver;

	public function __construct( ProductNcmResolver $ncmResolver ) {
		$this->ncmResolver = $ncmResolver;
	}

	/**
	 * @param \WC_Product $product
	 *
	 * @return array
	 */
	public function serialize( \WC_Product $product ): array {
		return [
			'id' => $product->get_id(),
			'name' => $product->get_name(),
			'slug' => $product->get_slug(),
			'type' => $product->get_type(),
			'status' => $product->get_status(),
			'description' => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'sku' => $product->get_sku(),
			'ncm' => $this->ncmResolver->resolve( $product ),
			'regular_price' => (string) $product->get_regular_price(),
			'sale_price' => (string) $product->get_sale_price(),
			'date_on_sale_from' => $this->formatDate( $product->get_date_on_sale_from() ),
			'date_on_sale_to' => $this->formatDate( $product->get_date_on_sale_to() ),
			'virtual' => (bool) $product->get_virtual(),
			'downloadable' => (bool) $product->get_downloadable(),
			'downloads' => $this->serializeDownloads( $product ),
			'manage_stock' => (bool) $product->get_manage_stock(),
			'stock_quantity' => $product->get_stock_quantity(),
			'stock_status' => $product->get_stock_status(),
			'backorders' => $product->get_backorders(),
			'weight' => (string) $product->get_weight(),
			'dimensions' => [
				'length' => (string) $product->get_length(),
				'width' => (string) $product->get_width(),
				'height' => (string) $product->get_height(),
			],
			'weight_unit' => (string) get_option( 'woocommerce_weight_unit' ),
			'dimension_unit' => (string) get_option( 'woocommerce_dimension_unit' ),
			'categories' => $this->serializeTerms( $product->get_category_ids(), 'product_cat' ),
			'tags' => $this->serializeTerms( $product->get_tag_ids(), 'product_tag' ),
			'images' => $this->serializeImages( $product ),
			'attributes' => $this->serializeAttributes( $product ),
			'variations' => $this->serializeVariations( $product ),
		];
	}

	/**
	 * @param int[] $term_ids
	 * @param string $taxonomy
	 *
	 * @return array
	 */
	protected function serializeTerms( array $term_ids, string $taxonomy ): array {
		$terms = [];

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$terms[] = [
				'id' => $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
			];
		}

		return $terms;
	}

	/**
	 * Downloadable files, wc/v3 style (`[{ id, name, file }]`).
	 *
	 * @param \WC_Product $product
	 *
	 * @return array
	 */
	protected function serializeDownloads( \WC_Product $product ): array {
		$downloads = [];

		foreach ( $product->get_downloads() as $download ) {
			$downloads[] = [
				'id'   => (string) $download->get_id(),
				'name' => (string) $download->get_name(),
				'file' => (string) $download->get_file(),
			];
		}

		return $downloads;
	}

	/**
	 * Main image + gallery, wc/v3 style.
	 *
	 * @param \WC_Product $product
	 *
	 * @return array
	 */
	protected function serializeImages( \WC_Product $product ): array {
		$image_ids = [];

		$main_image_id = (int) $product->get_image_id();
		if ( $main_image_id ) {
			$image_ids[] = $main_image_id;
		}

		foreach ( $product->get_gallery_image_ids() as $gallery_image_id ) {
			$image_ids[] = (int) $gallery_image_id;
		}

		$images = [];

		foreach ( $image_ids as $position => $image_id ) {
			$src = wp_get_attachment_url( $image_id );

			if ( ! $src ) {
				continue;
			}

			$images[] = [
				'id' => $image_id,
				'src' => $src,
				'alt' => (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
				'position' => $position,
			];
		}

		return $images;
	}

	/**
	 * @param \WC_Product $product
	 *
	 * @return array
	 */
	protected function serializeAttributes( \WC_Product $product ): array {
		$attributes = [];

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			if ( $attribute->is_taxonomy() ) {
				$options = array_map(
					fn( $term ) => $term->name,
					array_filter( (array) $attribute->get_terms(), fn( $term ) => $term instanceof \WP_Term )
				);
				$options = array_values( $options );
			} else {
				$options = array_values( (array) $attribute->get_options() );
			}

			$attributes[] = [
				'id' => $attribute->get_id(),
				'name' => wc_attribute_label( $attribute->get_name() ),
				'position' => (int) $attribute->get_position(),
				'variation' => (bool) $attribute->get_variation(),
				'options' => $options,
			];
		}

		return $attributes;
	}

	/**
	 * Embedded variations (variable products only).
	 *
	 * @param \WC_Product $product
	 *
	 * @return array
	 */
	protected function serializeVariations( \WC_Product $product ): array {
		if ( ! $product->is_type( 'variable' ) ) {
			return [];
		}

		$variations = [];

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}

			$variations[] = $this->serializeVariation( $variation );
		}

		return $variations;
	}

	/**
	 * @param \WC_Product_Variation $variation
	 *
	 * @return array
	 */
	protected function serializeVariation( \WC_Product_Variation $variation ): array {
		return [
			'id' => $variation->get_id(),
			'sku' => $variation->get_sku(),
			'ncm' => $this->ncmResolver->resolve( $variation ),
			'status' => $variation->get_status(),
			'regular_price' => (string) $variation->get_regular_price(),
			'sale_price' => (string) $variation->get_sale_price(),
			'date_on_sale_from' => $this->formatDate( $variation->get_date_on_sale_from() ),
			'date_on_sale_to' => $this->formatDate( $variation->get_date_on_sale_to() ),
			'manage_stock' => (bool) $variation->get_manage_stock(),
			'stock_quantity' => $variation->get_stock_quantity(),
			'stock_status' => $variation->get_stock_status(),
			'backorders' => $variation->get_backorders(),
			'weight' => (string) $variation->get_weight(),
			'dimensions' => [
				'length' => (string) $variation->get_length(),
				'width' => (string) $variation->get_width(),
				'height' => (string) $variation->get_height(),
			],
			'image' => $this->serializeVariationImage( $variation ),
			'attributes' => $this->serializeVariationAttributes( $variation ),
		];
	}

	/**
	 * @param \WC_Product_Variation $variation
	 *
	 * @return array|null
	 */
	protected function serializeVariationImage( \WC_Product_Variation $variation ): ?array {
		$image_id = (int) $variation->get_image_id();

		if ( ! $image_id ) {
			return null;
		}

		$src = wp_get_attachment_url( $image_id );

		if ( ! $src ) {
			return null;
		}

		return [
			'id' => $image_id,
			'src' => $src,
			'alt' => (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
		];
	}

	/**
	 * wc/v3 style variation attributes: [{name, option}].
	 *
	 * @param \WC_Product_Variation $variation
	 *
	 * @return array
	 */
	protected function serializeVariationAttributes( \WC_Product_Variation $variation ): array {
		$attributes = [];

		foreach ( $variation->get_attributes() as $attribute_name => $option ) {
			if ( '' === (string) $option ) {
				continue;
			}

			if ( taxonomy_exists( $attribute_name ) ) {
				$term = get_term_by( 'slug', $option, $attribute_name );
				$option_label = $term instanceof \WP_Term ? $term->name : (string) $option;
			} else {
				$option_label = (string) $option;
			}

			$attributes[] = [
				'name' => wc_attribute_label( $attribute_name, $variation ),
				'option' => $option_label,
			];
		}

		return $attributes;
	}

	/**
	 * @param \WC_DateTime|null $date
	 *
	 * @return string|null
	 */
	protected function formatDate( $date ): ?string {
		if ( ! $date instanceof \WC_DateTime ) {
			return null;
		}

		return $date->date( 'Y-m-d\TH:i:s' );
	}
}
