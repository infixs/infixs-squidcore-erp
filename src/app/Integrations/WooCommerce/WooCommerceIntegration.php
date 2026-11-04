<?php

namespace Infixs\SquidCore\Integrations\WooCommerce;

use Infixs\SquidCore\AvelPress\Config\SettingsRepository;
use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosTrackingCodes;
use Infixs\SquidCore\Integrations\WooCommerce\Products\CorreiosNcmCopy;
use Infixs\SquidCore\Services\ConnectionService;

defined( 'ABSPATH' ) || exit;

/**
 * Real-time WooCommerce -> SquidCore sync.
 *
 * Listens to catalog and order hooks and delegates the async push to the
 * SyncDispatcher. The backend deduplicates events by hash, so this class
 * only needs to report changes, never to detect their origin.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class WooCommerceIntegration {

	/**
	 * @var SyncDispatcher
	 */
	protected $dispatcher;

	/**
	 * @var SettingsRepository
	 */
	protected $settings;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	public function __construct( SyncDispatcher $dispatcher, SettingsRepository $settings, ConnectionService $connection ) {
		$this->dispatcher = $dispatcher;
		$this->settings = $settings;
		$this->connection = $connection;
	}

	/**
	 * Registers sync hooks when WooCommerce is active, the connection is
	 * confirmed and at least one sync group is enabled.
	 */
	public function init(): void {
		if ( ! $this->isWooCommerceActive() ) {
			return;
		}

		if ( ! $this->isConnectionConfigured() ) {
			return;
		}

		if ( $this->isCatalogSyncEnabled() ) {
			$this->registerCatalogHooks();
		}

		if ( $this->isOrdersSyncEnabled() ) {
			$this->registerOrderHooks();
		}
	}

	protected function registerCatalogHooks(): void {
		// Products.
		add_action( 'woocommerce_new_product', [ $this, 'onProductCreated' ] );
		add_action( 'woocommerce_update_product', [ $this, 'onProductUpdated' ] );
		add_action( 'woocommerce_delete_product', [ $this, 'onProductDeleted' ] );
		add_action( 'wp_trash_post', [ $this, 'onPostTrashed' ] );
		add_action( 'untrashed_post', [ $this, 'onPostUntrashed' ] );

		// Variations: push the parent product (variations travel embedded).
		add_action( 'woocommerce_save_product_variation', [ $this, 'onVariationSaved' ] );
		add_action( 'woocommerce_delete_product_variation', [ $this, 'onVariationDeleted' ] );

		// The NCM changed in the background, without a product save.
		add_action( CorreiosNcmCopy::ADOPTED_ACTION, [ $this, 'onProductNcmAdopted' ] );

		// Categories.
		add_action( 'created_product_cat', [ $this, 'onCategoryCreated' ] );
		add_action( 'edited_product_cat', [ $this, 'onCategoryUpdated' ] );
		add_action( 'delete_product_cat', [ $this, 'onCategoryDeleted' ] );
	}

	protected function registerOrderHooks(): void {
		add_action( 'woocommerce_new_order', [ $this, 'onOrderCreated' ] );
		add_action( 'woocommerce_order_status_changed', [ $this, 'onOrderChanged' ] );
		add_action( 'woocommerce_payment_complete', [ $this, 'onOrderChanged' ] );

		// A tracking code added by Correios Automático changes no WooCommerce field: the order goes again so SquidCore
		// gets the code (and the customer the notice).
		add_action( CorreiosTrackingCodes::ADDED_ACTION, [ $this, 'onTrackingCodeAdded' ], 10, 2 );
	}

	/**
	 * @param int $product_id
	 */
	public function onProductCreated( $product_id ): void {
		$this->dispatcher->dispatch( 'product.created', (int) $product_id );
	}

	/**
	 * @param int $product_id
	 */
	public function onProductUpdated( $product_id ): void {
		$this->dispatcher->dispatch( 'product.updated', (int) $product_id );
	}

	/**
	 * A Correios Automático NCM adopted in the background changed the NCM of the product: pushed behind the store's
	 * day-to-day changes, since a whole catalog may change at once. Never a product in the trash.
	 *
	 * @param int $product_id
	 */
	public function onProductNcmAdopted( $product_id ): void {
		if ( in_array( get_post_status( (int) $product_id ), [ 'trash', 'auto-draft' ], true ) ) {
			return;
		}

		$this->dispatcher->dispatch( 'product.updated', (int) $product_id, SyncDispatcher::DISPATCH_DELAY, SyncDispatcher::BULK_PRIORITY );
	}

	/**
	 * @param int $product_id
	 */
	public function onProductDeleted( $product_id ): void {
		$this->dispatcher->dispatch( 'product.deleted', (int) $product_id );
	}

	/**
	 * @param int $post_id
	 */
	public function onPostTrashed( $post_id ): void {
		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}

		$this->dispatcher->dispatch( 'product.deleted', (int) $post_id );
	}

	/**
	 * @param int $post_id
	 */
	public function onPostUntrashed( $post_id ): void {
		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}

		$this->dispatcher->dispatch( 'product.updated', (int) $post_id );
	}

	/**
	 * @param int $variation_id
	 */
	public function onVariationSaved( $variation_id ): void {
		$parent_id = $this->resolveVariationParentId( (int) $variation_id );

		if ( $parent_id ) {
			$this->dispatcher->dispatch( 'product.updated', $parent_id );
		}
	}

	/**
	 * @param int $variation_id
	 */
	public function onVariationDeleted( $variation_id ): void {
		$parent_id = $this->resolveVariationParentId( (int) $variation_id );

		if ( $parent_id ) {
			$this->dispatcher->dispatch( 'product.updated', $parent_id );
		}
	}

	/**
	 * @param int $term_id
	 */
	public function onCategoryCreated( $term_id ): void {
		$this->dispatcher->dispatch( 'category.created', (int) $term_id );
	}

	/**
	 * @param int $term_id
	 */
	public function onCategoryUpdated( $term_id ): void {
		$this->dispatcher->dispatch( 'category.updated', (int) $term_id );
	}

	/**
	 * The hook delivers the id of the already deleted term.
	 *
	 * @param int $term_id
	 */
	public function onCategoryDeleted( $term_id ): void {
		$this->dispatcher->dispatch( 'category.deleted', (int) $term_id );
	}

	/**
	 * @param int $order_id
	 */
	public function onOrderCreated( $order_id ): void {
		$this->dispatcher->dispatch( 'order.created', (int) $order_id );
	}

	/**
	 * @param int $order_id
	 */
	public function onOrderChanged( $order_id ): void {
		$this->dispatcher->dispatch( 'order.updated', (int) $order_id );
	}

	/**
	 * Correios Automático added a tracking code to an order. The codes of the volumes of one order arrive in a row
	 * and collapse into one push.
	 *
	 * @param string $code
	 * @param int $order_id
	 */
	public function onTrackingCodeAdded( $code, $order_id ): void {
		if ( ! absint( $order_id ) ) {
			return;
		}

		$this->dispatcher->dispatch( 'order.updated', absint( $order_id ) );
	}

	/**
	 * Resolves a variation's parent product id, tolerating the variation
	 * being already deleted (woocommerce_delete_product_variation fires
	 * after removal).
	 *
	 * @param int $variation_id
	 *
	 * @return int Zero when the parent cannot be resolved.
	 */
	protected function resolveVariationParentId( int $variation_id ): int {
		if ( function_exists( 'wc_get_product' ) ) {
			$variation = wc_get_product( $variation_id );

			if ( $variation instanceof \WC_Product ) {
				return (int) $variation->get_parent_id();
			}
		}

		return (int) wp_get_post_parent_id( $variation_id );
	}

	protected function isWooCommerceActive(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Only a token confirmed by the current API sends changes: one known to fail would only queue refused pushes.
	 */
	protected function isConnectionConfigured(): bool {
		return $this->connection->isConnected();
	}

	protected function isCatalogSyncEnabled(): bool {
		return $this->settings->boolean( 'sync.catalog', true );
	}

	protected function isOrdersSyncEnabled(): bool {
		return $this->settings->boolean( 'sync.orders', true );
	}
}
