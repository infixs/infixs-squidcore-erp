<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Serializers;

use Infixs\SquidCore\Integrations\WooCommerce\Products\ProductNcmResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Serializes a WC_Order into the payload sent to SquidCore.
 *
 * Uses only the WooCommerce CRUD API (HPOS-safe).
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class OrderSerializer {

	/**
	 * Brazilian customer fields kept in order metas `_{billing|shipping}_{field}` by Brazilian Market on WooCommerce
	 * and Infixs Checkout Fields.
	 */
	private const BRAZILIAN_FIELDS = [ 'persontype', 'cpf', 'cnpj', 'ie', 'rg', 'number', 'neighborhood', 'cellphone' ];

	/**
	 * @var ProductNcmResolver
	 */
	protected $ncmResolver;

	public function __construct( ProductNcmResolver $ncmResolver ) {
		$this->ncmResolver = $ncmResolver;
	}

	/**
	 * @param \WC_Order $order
	 *
	 * @return array
	 */
	public function serialize( \WC_Order $order ): array {
		$date_created = $order->get_date_created();
		$date_paid = $order->get_date_paid();

		return [
			'id' => $order->get_id(),
			'number' => (string) $order->get_order_number(),
			'status' => $order->get_status(),
			'currency' => $order->get_currency(),
			'total' => (string) $order->get_total(),
			'discount_total' => (string) $order->get_discount_total(),
			'shipping_total' => (string) $order->get_shipping_total(),
			'total_tax' => (string) $order->get_total_tax(),
			'date_created' => $date_created instanceof \WC_DateTime ? $date_created->date( 'Y-m-d\TH:i:s' ) : null,
			'date_paid' => $date_paid instanceof \WC_DateTime ? $date_paid->format( DATE_ATOM ) : null,
			'customer_id' => (int) $order->get_customer_id(),
			'payment_method' => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'customer_note' => $order->get_customer_note(),
			'billing' => [
				'first_name' => $order->get_billing_first_name(),
				'last_name' => $order->get_billing_last_name(),
				'company' => $order->get_billing_company(),
				'email' => $order->get_billing_email(),
				'phone' => $order->get_billing_phone(),
				'address_1' => $order->get_billing_address_1(),
				'address_2' => $order->get_billing_address_2(),
				'city' => $order->get_billing_city(),
				'state' => $order->get_billing_state(),
				'postcode' => $order->get_billing_postcode(),
				'country' => $order->get_billing_country(),
			] + $this->brazilianFields( $order, 'billing' ),
			'shipping' => [
				'first_name' => $order->get_shipping_first_name(),
				'last_name' => $order->get_shipping_last_name(),
				'company' => $order->get_shipping_company(),
				'phone' => $order->get_shipping_phone(),
				'address_1' => $order->get_shipping_address_1(),
				'address_2' => $order->get_shipping_address_2(),
				'city' => $order->get_shipping_city(),
				'state' => $order->get_shipping_state(),
				'postcode' => $order->get_shipping_postcode(),
				'country' => $order->get_shipping_country(),
			] + $this->brazilianFields( $order, 'shipping' ),
			'line_items' => $this->serializeLineItems( $order ),
			'shipping_lines' => $this->serializeShippingLines( $order ),
			'fee_lines' => $this->serializeFeeLines( $order ),
			'coupon_lines' => $this->serializeCouponLines( $order ),
		];
	}

	/**
	 * @param \WC_Order $order
	 * @param string $type "billing" or "shipping".
	 *
	 * @return array<string, string|null> Null for the fields the order does not have.
	 */
	protected function brazilianFields( \WC_Order $order, string $type ): array {
		$fields = [];

		foreach ( self::BRAZILIAN_FIELDS as $field ) {
			$value = trim( (string) $order->get_meta( "_{$type}_{$field}", true ) );
			$fields[ $field ] = $value !== '' ? $value : null;
		}

		return $fields;
	}

	/**
	 * @param \WC_Order $order
	 *
	 * @return array
	 */
	protected function serializeLineItems( \WC_Order $order ): array {
		$line_items = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$quantity = (int) $item->get_quantity();

			$line_items[] = [
				'id' => $item->get_id(),
				'product_id' => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'name' => $item->get_name(),
				'sku' => $product instanceof \WC_Product ? $product->get_sku() : null,
				'quantity' => $quantity,
				'price' => $quantity > 0 ? (float) ( (float) $item->get_total() / $quantity ) : 0.0,
				'subtotal' => (string) $item->get_subtotal(),
				'subtotal_tax' => (string) $item->get_subtotal_tax(),
				'total' => (string) $item->get_total(),
				'total_tax' => (string) $item->get_total_tax(),
				'ncm' => $product instanceof \WC_Product ? $this->ncmResolver->resolve( $product ) : null,
			];
		}

		return $line_items;
	}

	protected function serializeShippingLines( \WC_Order $order ): array {
		$lines = [];

		foreach ( $order->get_shipping_methods() as $item ) {
			$lines[] = [
				'id' => $item->get_id(),
				'method_id' => $item->get_method_id(),
				'instance_id' => (string) $item->get_instance_id(),
				'method_title' => $item->get_method_title(),
				'total' => (string) $item->get_total(),
				'total_tax' => (string) $item->get_total_tax(),
			];
		}

		return $lines;
	}

	protected function serializeFeeLines( \WC_Order $order ): array {
		$lines = [];

		foreach ( $order->get_fees() as $item ) {
			$lines[] = [
				'name' => $item->get_name(),
				'total' => (string) $item->get_total(),
			];
		}

		return $lines;
	}

	protected function serializeCouponLines( \WC_Order $order ): array {
		$lines = [];

		foreach ( $order->get_coupons() as $item ) {
			$lines[] = [
				'code' => $item->get_code(),
				'discount' => (string) $item->get_discount(),
			];
		}

		return $lines;
	}
}
