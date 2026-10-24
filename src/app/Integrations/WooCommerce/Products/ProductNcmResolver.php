<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products;

defined( 'ABSPATH' ) || exit;

/**
 * NCM (Mercosur product classification) of a WooCommerce product, as sent to SquidCore.
 *
 * The NCM field of SquidCore counts; the one of Correios Automático only for products that never had the SquidCore
 * one (ProductNcmStore). A variation without NCM uses its parent's. Customizations can change the result through the
 * `infixs_squidcore_product_ncm` filter.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class ProductNcmResolver {

	public const META_KEY = ProductNcmStore::META_KEY;

	public const CORREIOS_META_KEY = ProductNcmStore::CORREIOS_META_KEY;

	/**
	 * @var ProductNcmStore
	 */
	protected $store;

	public function __construct( ProductNcmStore $store ) {
		$this->store = $store;
	}

	/**
	 * @return string|null Digits only, null when the product has no NCM.
	 */
	public function resolve( \WC_Product $product ): ?string {
		$ncm = $this->store->effective( $product );

		/**
		 * Filters the NCM sent to SquidCore for a product.
		 *
		 * @since 1.0.0
		 *
		 * @param string      $ncm     NCM found in the product metas (digits only), blank when none.
		 * @param \WC_Product $product Product or variation.
		 */
		$ncm = self::digits( (string) apply_filters( 'infixs_squidcore_product_ncm', $ncm, $product ) );

		return $ncm !== '' ? $ncm : null;
	}

	public static function digits( string $value ): string {
		return NcmCode::normalize( $value );
	}
}
