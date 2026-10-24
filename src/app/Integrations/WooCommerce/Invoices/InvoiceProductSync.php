<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

use Infixs\SquidCore\Integrations\WooCommerce\Products\ProductNcmResolver;
use Infixs\SquidCore\Integrations\WooCommerce\SyncDispatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Products an invoice block may owe only to a product push that has not run yet: an order paid seconds after its
 * product was created reaches SquidCore before the product (product.unmapped), and an NCM saved in WooCommerce reaches
 * it with the next product push (product.fiscal.ncm). Sending those products right away lets the emission go on.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceProductSync {

	public const UNMAPPED = 'product.unmapped';

	public const MISSING_NCM = 'product.fiscal.ncm';

	/**
	 * @var SyncDispatcher
	 */
	protected $dispatcher;

	/**
	 * @var ProductNcmResolver
	 */
	protected $ncmResolver;

	public function __construct( SyncDispatcher $dispatcher, ProductNcmResolver $ncmResolver ) {
		$this->dispatcher = $dispatcher;
		$this->ncmResolver = $ncmResolver;
	}

	/**
	 * WooCommerce products (the parent of a variation) worth sending again: those SquidCore does not know yet, and
	 * those it sees without NCM while WooCommerce has one.
	 *
	 * @param array[] $reasons Blocking reasons (NfeBlockingReason).
	 *
	 * @return int[] Product ids, without repetition.
	 */
	public function staleProducts( array $reasons ): array {
		$ids = [];

		foreach ( $reasons as $reason ) {
			$code = (string) ( $reason['code'] ?? '' );
			$product_id = (int) ( $reason['wcProductId'] ?? 0 );

			if ( $product_id <= 0 || ! in_array( $code, [ self::UNMAPPED, self::MISSING_NCM ], true ) ) {
				continue;
			}

			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			// No NCM in WooCommerce either: sending the product again changes nothing.
			if ( $code === self::MISSING_NCM && $this->ncmResolver->resolve( $product ) === null ) {
				continue;
			}

			$ids[] = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Sends the products now, one request each.
	 *
	 * @param int[] $productIds WooCommerce product ids.
	 *
	 * @return int How many SquidCore took.
	 */
	public function push( array $productIds ): int {
		$sent = 0;

		foreach ( $productIds as $product_id ) {
			if ( $this->dispatcher->pushNow( 'product.updated', (int) $product_id ) === true ) {
				$sent++;
			}
		}

		return $sent;
	}
}
