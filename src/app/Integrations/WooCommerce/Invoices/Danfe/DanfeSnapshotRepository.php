<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the simplified DANFE snapshot in an order meta, through the WooCommerce CRUD (works with and without HPOS).
 *
 * @package Infixs\SquidCore
 * @since   1.4.0
 */
class DanfeSnapshotRepository {

	public const META_KEY = '_infixs_squidcore_invoice_danfe';

	/**
	 * @return DanfeSnapshot|null Null when the order has none.
	 */
	public function find( \WC_Order $order ): ?DanfeSnapshot {
		$data = $order->get_meta( self::META_KEY, true );

		if ( ! is_array( $data ) || empty( $data['v'] ) || empty( $data['status'] ) ) {
			return null;
		}

		return new DanfeSnapshot( $data );
	}

	/**
	 * Saves only the meta, so no status or order hooks run again.
	 */
	public function save( \WC_Order $order, DanfeSnapshot $snapshot ): void {
		$order->update_meta_data( self::META_KEY, $snapshot->toArray() );
		$order->save_meta_data();
	}

	public function delete( \WC_Order $order ): void {
		if ( ! $order->meta_exists( self::META_KEY ) ) {
			return;
		}

		$order->delete_meta_data( self::META_KEY );
		$order->save_meta_data();
	}
}
