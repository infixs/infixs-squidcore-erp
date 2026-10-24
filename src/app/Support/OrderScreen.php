<?php

namespace Infixs\SquidCore\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens of WooCommerce orders, with and without HPOS (custom order tables).
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class OrderScreen {

	/**
	 * Screen id of the order list and editor with HPOS (the admin page `wc-orders`).
	 */
	public const HPOS_SCREEN_ID = 'woocommerce_page_wc-orders';

	/**
	 * Every screen id the HPOS order page may have: WooCommerce uses `admin_page_wc-orders` for users who do not see
	 * its menu.
	 */
	public const HPOS_SCREEN_IDS = [ self::HPOS_SCREEN_ID, 'admin_page_wc-orders' ];

	/**
	 * Screen id of the order edit screen, used to register meta boxes.
	 */
	public static function id(): string {
		if ( ! self::usesOrderTables() ) {
			return 'shop_order';
		}

		// The WooCommerce admin functions are only loaded on admin requests.
		return function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : self::HPOS_SCREEN_ID;
	}

	/**
	 * Whether the current admin screen edits one order.
	 */
	public static function isOrderEditScreen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || $screen->id !== self::id() ) {
			return false;
		}

		// HPOS uses the same screen id for the list and the editor; only the editor has an action.
		if ( self::usesOrderTables() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which screen is open.
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

			return in_array( $action, [ 'edit', 'new' ], true );
		}

		return $screen->base === 'post';
	}

	/**
	 * Whether the current admin screen lists orders.
	 */
	public static function isOrderListScreen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		if ( self::usesOrderTables() ) {
			return $screen->id === self::id() && ! self::isOrderEditScreen();
		}

		return $screen->id === 'edit-shop_order';
	}

	/**
	 * Whether WooCommerce stores orders in its own tables (HPOS) instead of posts.
	 */
	public static function usesOrderTables(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
