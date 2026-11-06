<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * The volumes a shipping line of a carrier plugin of Infixs (Correios Automático, JadLog, Loggi, PEX) ships and their
 * weight, for the volumes of the invoice. Those plugins freeze on the line the weight in kg of what it ships; the
 * meta of the same name on a line of another plugin may be in another unit, so it is not read.
 *
 * Correios Automático gives each volume of a shipment its own line (VolumeLineExpander): the line then carries the
 * weight of its own box and is one volume. Its "_infixs_volume_total" is the number of volumes of the whole shipment,
 * repeated on each of its lines, and "package_qty" the number of products in the box, so neither is counted. A line
 * still holding the volumes it was quoted in ("_volumes", the order was not expanded) ships all of them, with the
 * weight of the whole shipment.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class ShippingLinePackage {

	/**
	 * Shipping methods of the carrier plugins of Infixs ("infixs-correios-automatico", "infixs-jadlog-automatico").
	 */
	private const INFIXS_METHOD_PATTERN = '/^infixs-[a-z0-9-]+-automatico$/';

	/**
	 * Metas Correios Automático writes on a line of another method it ships, with the weight of the shipment: a linked
	 * free shipping or flat rate, and the first volume of a freight another system wrote.
	 */
	private const CORREIOS_LINE_METAS = [ '_infixs_linked_instance_id', self::VOLUME_INDEX_META ];

	private const WEIGHT_META = '_weight';

	private const VOLUME_INDEX_META = '_infixs_volume_index';

	private const VOLUMES_META = '_volumes';

	/**
	 * The volumes of a shipping line, or null for a line of another plugin. Without a weight above zero the line still
	 * says how many volumes it ships, with the weight as null.
	 *
	 * @param \WC_Order_Item_Shipping $item
	 *
	 * @return array{quantity: int, weight_kg: string|null}|null Weight with three decimals and a dot ("1.250").
	 */
	public static function get( \WC_Order_Item_Shipping $item ): ?array {
		if ( ! self::isInfixsLine( $item ) ) {
			return null;
		}

		return [
			'quantity' => self::quantity( $item ),
			'weight_kg' => self::weight( $item->get_meta( self::WEIGHT_META ) ),
		];
	}

	private static function isInfixsLine( \WC_Order_Item_Shipping $item ): bool {
		if ( preg_match( self::INFIXS_METHOD_PATTERN, (string) $item->get_method_id() ) ) {
			return true;
		}

		foreach ( self::CORREIOS_LINE_METAS as $key ) {
			if ( absint( $item->get_meta( $key ) ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	private static function quantity( \WC_Order_Item_Shipping $item ): int {
		if ( absint( $item->get_meta( self::VOLUME_INDEX_META ) ) > 0 ) {
			return 1;
		}

		$volumes = $item->get_meta( self::VOLUMES_META );

		if ( is_string( $volumes ) ) {
			$volumes = json_decode( $volumes, true );
		}

		return is_array( $volumes ) && count( $volumes ) > 1 ? count( $volumes ) : 1;
	}

	/**
	 * The weight in kg with three decimals, or null when it is not a number above zero. A decimal comma is read too:
	 * PHP 7 writes a float with the separator of the locale.
	 *
	 * @param mixed $raw
	 */
	private static function weight( $raw ): ?string {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			$raw = false === strpos( $raw, '.' ) ? str_replace( ',', '.', $raw ) : $raw;
		}

		if ( ! is_numeric( $raw ) ) {
			return null;
		}

		$weight = round( (float) $raw, 3 );

		return is_finite( $weight ) && $weight > 0 ? number_format( $weight, 3, '.', '' ) : null;
	}
}
