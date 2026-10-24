<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Serializers;

defined( 'ABSPATH' ) || exit;

/**
 * Serializes a product_cat term into the payload sent to SquidCore.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class CategorySerializer {

	/**
	 * @param \WP_Term $term
	 *
	 * @return array
	 */
	public function serialize( \WP_Term $term ): array {
		return [
			'id' => $term->term_id,
			'name' => $term->name,
			'slug' => $term->slug,
			'parent' => (int) $term->parent,
			'description' => $term->description,
		];
	}
}
