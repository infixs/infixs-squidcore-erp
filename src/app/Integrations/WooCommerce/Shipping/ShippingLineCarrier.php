<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Shipping;

use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosShippingCarrier;

defined( 'ABSPATH' ) || exit;

/**
 * The carrier that ships a shipping line of an order, for the transport group of the invoice: only what a plugin
 * records about the carrier counts, never the title of the method, which the store writes freely.
 *
 * In this order: a plugin answering the filter `infixs_squidcore_order_shipping_carrier`; the carrier Correios
 * Automático and its extensions give the line (a slug of the catalog of SquidCore, e.g. "correios", "jadlog"); the
 * carrier the Melhor Envio plugin saved on the line (a name, e.g. "Jadlog", which SquidCore turns into its slug).
 * SquidCore alone turns a name into a slug, and a slug into the fiscal data of the carrier, and it alone reads the
 * method of a line no plugin here answered for.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class ShippingLineCarrier {

	/**
	 * Filter any plugin may answer with the carrier of a shipping line (null, shipping line, order): an array with its
	 * slug or its name, and its service if any, or the slug alone. Any other answer (false, an empty array, a service
	 * alone) says the line has no carrier; only null lets the plugin look further. The apply_filters() call spells it
	 * out, as every hook the plugin fires.
	 */
	public const FILTER = 'infixs_squidcore_order_shipping_carrier';

	/**
	 * Longest name and service kept, the size of the name of a carrier on the invoice.
	 */
	private const MAX_TEXT = 60;

	/**
	 * Metas of the Melhor Envio plugin on the lines of its single method "melhor_envio" (since 3.0).
	 */
	private const MELHOR_ENVIO_COMPANY_META = 'me_company_name';

	private const MELHOR_ENVIO_SERVICE_META = 'me_service_name';

	/**
	 * Before 3.0 Melhor Envio had one method per service ("melhorenvio_jadlog_package"), each saving the name of its
	 * company in the meta "company"; the meta is read only on those lines, the key being too common to trust on others.
	 */
	private const MELHOR_ENVIO_LEGACY_PREFIX = 'melhorenvio_';

	private const MELHOR_ENVIO_LEGACY_COMPANY_META = 'company';

	/**
	 * @var CorreiosShippingCarrier|null
	 */
	protected $correios;

	/**
	 * @param CorreiosShippingCarrier|null $correios Without it, the lines of Correios Automático are left to the
	 *                                               filter and to SquidCore.
	 */
	public function __construct( ?CorreiosShippingCarrier $correios = null ) {
		$this->correios = $correios;
	}

	/**
	 * The carrier of a shipping line, or null when nothing on the line tells it.
	 *
	 * @param \WC_Order_Item_Shipping $item
	 * @param \WC_Order $order
	 *
	 * @return array{slug: string|null, name: string|null, service: string|null}|null
	 */
	public function resolve( \WC_Order_Item_Shipping $item, \WC_Order $order ): ?array {
		$answer = apply_filters( 'infixs_squidcore_order_shipping_carrier', null, $item, $order );

		if ( null !== $answer ) {
			return is_string( $answer ) ? $this->carrier( $answer, null, null ) : $this->fromFilter( $answer );
		}

		$slug = $this->correios ? $this->correios->slug( $item ) : null;

		if ( null !== $slug ) {
			return $this->carrier( $slug, null, $this->correios->service( $item ) );
		}

		return $this->fromMelhorEnvio( $item );
	}

	/**
	 * @param mixed $answer
	 */
	private function fromFilter( $answer ): ?array {
		if ( ! is_array( $answer ) ) {
			return null;
		}

		return $this->carrier( $answer['slug'] ?? null, $answer['name'] ?? null, $answer['service'] ?? null );
	}

	private function fromMelhorEnvio( \WC_Order_Item_Shipping $item ): ?array {
		$company = $item->get_meta( self::MELHOR_ENVIO_COMPANY_META );

		if ( $this->text( $company ) !== null ) {
			return $this->carrier( null, $company, $item->get_meta( self::MELHOR_ENVIO_SERVICE_META ) );
		}

		if ( 0 !== strpos( (string) $item->get_method_id(), self::MELHOR_ENVIO_LEGACY_PREFIX ) ) {
			return null;
		}

		return $this->carrier( null, $item->get_meta( self::MELHOR_ENVIO_LEGACY_COMPANY_META ), null );
	}

	/**
	 * The carrier with each field trimmed, a missing one as null; null without a slug or a name, which a service alone
	 * does not tell.
	 *
	 * @param mixed $slug
	 * @param mixed $name
	 * @param mixed $service
	 */
	private function carrier( $slug, $name, $service ): ?array {
		$slug = is_string( $slug ) ? sanitize_key( $slug ) : '';

		$carrier = [
			'slug' => '' !== $slug ? $slug : null,
			'name' => $this->text( $name ),
			'service' => $this->text( $service ),
		];

		return null !== $carrier['slug'] || null !== $carrier['name'] ? $carrier : null;
	}

	/**
	 * @param mixed $value
	 */
	private function text( $value ): ?string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return null;
		}

		$text = trim( mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) ), 0, self::MAX_TEXT ) );

		return '' !== $text ? $text : null;
	}
}
