<?php

namespace Infixs\SquidCore\Integrations\CorreiosAutomatico;

defined( 'ABSPATH' ) || exit;

/**
 * The carrier Correios Automático and its extensions (JadLog, Loggi, PEX) give a shipping line, as their own order
 * screen does: a line of Correios Automático, or of a method it converted, is "correios"; a line of an extension
 * answers with the slug of the extension (filter `infixs_correios_automatico_shipping_item_carrier`); and the
 * carriers Correios Automático declares for its order list (CarrierRegistry, since 1.11.0) answer for the lines of
 * the methods they ship, the free shipping and flat rates linked to Correios and the plugins they replaced included.
 *
 * Nothing is listed here: the lists live in Correios Automático, so a carrier it learns reaches SquidCore without a
 * new release of this plugin. Features are detected by the classes that implement them, never by version numbers.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class CorreiosShippingCarrier {

	/**
	 * Shipping method of Correios Automático.
	 */
	public const METHOD_ID = 'infixs-correios-automatico';

	/**
	 * Carrier slug of the lines of Correios Automático.
	 */
	private const CORREIOS = 'correios';

	/**
	 * Carriers Correios Automático declares for its order list.
	 */
	private const REGISTRY_CLASS = 'Infixs\\CorreiosAutomatico\\Core\\Shipping\\CarrierRegistry';

	/**
	 * Services of the Correios contracts, by product code.
	 */
	private const SERVICE_CODES_CLASS = 'Infixs\\CorreiosAutomatico\\Services\\Correios\\Enums\\DeliveryServiceCode';

	/**
	 * @var CorreiosAutomatico
	 */
	protected $correios;

	/**
	 * The carriers of the order list of Correios Automático, read once: building them queries the options and the
	 * shipping zones for every instance linked to Correios, and every line of every order pushed in the request asks.
	 *
	 * @var array|null
	 */
	private $registry = null;

	public function __construct( CorreiosAutomatico $correios ) {
		$this->correios = $correios;
	}

	/**
	 * The carrier slug of a shipping line, or null when Correios Automático is not active or no carrier claims it.
	 *
	 * @param \WC_Order_Item_Shipping $item
	 */
	public function slug( \WC_Order_Item_Shipping $item ): ?string {
		if ( ! $this->correios->isActive() ) {
			return null;
		}

		if ( self::METHOD_ID === $item->get_method_id() ) {
			return self::CORREIOS;
		}

		// The filter Correios Automático asks the carrier of a line it does not ship with (carrier slug, shipping line).
		$slug = $this->sanitizeSlug( apply_filters( 'infixs_correios_automatico_shipping_item_carrier', null, $item ) );

		return null !== $slug ? $slug : $this->registrySlug( $item );
	}

	/**
	 * The Correios service of a line Correios ships ("PAC", "SEDEX"), from the product code Correios Automático wrote
	 * on it: its own lines, and the lines of other methods it wrote its shipment on (a linked flat rate, the first
	 * volume of a freight another system wrote). Null for any other line, or when Correios Automático does not name
	 * the code.
	 *
	 * @param \WC_Order_Item_Shipping $item
	 */
	public function service( \WC_Order_Item_Shipping $item ): ?string {
		if ( ! method_exists( self::SERVICE_CODES_CLASS, 'getShortDescription' ) || self::CORREIOS !== $this->slug( $item ) ) {
			return null;
		}

		$code = $item->get_meta( 'shipping_product_code' );

		if ( ! is_scalar( $code ) || '' === trim( (string) $code ) ) {
			return null;
		}

		$service = call_user_func( [ self::SERVICE_CODES_CLASS, 'getShortDescription' ], trim( (string) $code ) );

		return is_string( $service ) && '' !== trim( $service ) ? trim( $service ) : null;
	}

	/**
	 * The carrier of the order list of Correios Automático that ships a line: by its method, by a meta Correios
	 * Automático wrote on it (a linked free shipping) or by the instance of its method (a linked flat rate).
	 *
	 * @param \WC_Order_Item_Shipping $item
	 */
	protected function registrySlug( \WC_Order_Item_Shipping $item ): ?string {
		$method_id = (string) $item->get_method_id();
		$instance_id = (int) $item->get_instance_id();

		foreach ( $this->registry() as $slug => $carrier ) {
			if ( ! is_array( $carrier ) ) {
				continue;
			}

			if ( in_array( $method_id, (array) ( $carrier['method_ids'] ?? [] ), true ) || $this->hasAnyMeta( $item, (array) ( $carrier['item_metas'] ?? [] ) ) ) {
				return $this->sanitizeSlug( $slug );
			}

			$instances = (array) ( $carrier['method_instances'] ?? [] );

			if ( $instance_id && isset( $instances[ $method_id ] ) && in_array( $instance_id, array_map( 'intval', (array) $instances[ $method_id ] ), true ) ) {
				return $this->sanitizeSlug( $slug );
			}
		}

		return null;
	}

	/**
	 * The carriers of the order list of Correios Automático, by slug; none before 1.11.0.
	 */
	private function registry(): array {
		if ( null === $this->registry ) {
			$carriers = method_exists( self::REGISTRY_CLASS, 'all' ) ? call_user_func( [ self::REGISTRY_CLASS, 'all' ] ) : [];
			$this->registry = is_array( $carriers ) ? $carriers : [];
		}

		return $this->registry;
	}

	/**
	 * @param \WC_Order_Item_Shipping $item
	 * @param array $keys
	 */
	private function hasAnyMeta( \WC_Order_Item_Shipping $item, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( is_string( $key ) && '' !== $key && ! empty( $item->get_meta( $key ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $slug
	 */
	private function sanitizeSlug( $slug ): ?string {
		$slug = is_string( $slug ) ? sanitize_key( $slug ) : '';

		return '' !== $slug ? $slug : null;
	}
}
