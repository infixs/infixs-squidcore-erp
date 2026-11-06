<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCode;
use Infixs\SquidCore\Integrations\WooCommerce\Products\ProductNcmStore;

defined( 'ABSPATH' ) || exit;

/**
 * Brings to the WooCommerce product an NCM SquidCore filled with the AI (the bulk filling, the order "Fill with AI"
 * and the emission that lets the AI fill), so the store counts the product as having an NCM.
 *
 * Only a product without a valid NCM here gets it: an NCM the store owner typed, valid, is never replaced. The NCM goes
 * on the product, never on a variation (a variation id stands for its parent), through ProductNcmStore::write(), which
 * also copies it to Correios Automático while it is active. Only the metas are saved, so no product hook runs and the
 * product is not sent back to SquidCore, which already has this NCM.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class ProductNcmWriter {

	public const WRITTEN = 'written';

	/**
	 * The product has a valid NCM here (typed meanwhile, for example): kept.
	 */
	public const KEPT = 'kept';

	public const GONE = 'gone';

	/**
	 * The NCM given is not 8 digits of an existing chapter.
	 */
	public const INVALID = 'invalid';

	private const NOT_WRITTEN_STATUSES = [ 'trash', 'auto-draft' ];

	/**
	 * @var ProductNcmStore
	 */
	protected $store;

	public function __construct( ProductNcmStore $store ) {
		$this->store = $store;
	}

	/**
	 * @return string WRITTEN, KEPT, GONE or INVALID.
	 */
	public function write( int $wcProductId, string $ncm ): string {
		$digits = NcmCode::normalize( $ncm );

		if ( ! NcmCode::isValid( $digits ) ) {
			return self::INVALID;
		}

		$product = $this->product( $wcProductId );

		if ( $product === null ) {
			return self::GONE;
		}

		if ( $this->store->state( $product )->isValid() ) {
			return self::KEPT;
		}

		$this->store->write( $product, $digits );
		$product->save_meta_data();

		return self::WRITTEN;
	}

	/**
	 * Writes the products SquidCore filled ({wcProductId, ncm…}), ignoring entries without a product of the store.
	 *
	 * @param mixed $filled
	 *
	 * @return int[] Products (the WooCommerce ids given) that got the NCM.
	 */
	public function writeFilled( $filled ): array {
		$written = [];

		foreach ( is_array( $filled ) ? $filled : [] as $item ) {
			$product_id = is_array( $item ) ? (int) ( $item['wcProductId'] ?? 0 ) : 0;

			if ( $product_id > 0 && $this->write( $product_id, (string) ( $item['ncm'] ?? '' ) ) === self::WRITTEN ) {
				$written[] = $product_id;
			}
		}

		return $written;
	}

	/**
	 * The product the NCM belongs to: the parent of a variation; null when it is not (or no longer) in the store.
	 */
	private function product( int $wcProductId ): ?\WC_Product {
		$product = $wcProductId > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $wcProductId ) : null;

		if ( $product instanceof \WC_Product && $product->get_parent_id() > 0 ) {
			$product = wc_get_product( $product->get_parent_id() );
		}

		if ( ! $product instanceof \WC_Product || in_array( $product->get_status(), self::NOT_WRITTEN_STATUSES, true ) ) {
			return null;
		}

		return $product;
	}
}
