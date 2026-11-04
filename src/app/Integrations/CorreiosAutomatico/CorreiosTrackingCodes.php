<?php

namespace Infixs\SquidCore\Integrations\CorreiosAutomatico;

defined( 'ABSPATH' ) || exit;

/**
 * The tracking codes Correios Automático keeps for an order, in the format of its own REST field: `tracking_codes` on
 * the wc/v3 orders (id, code, carrier, description, category, date_created_gmt), so SquidCore reads the same thing
 * from the order push as from the WooCommerce REST API.
 *
 * Only the codes of the objects travelling to the customer go: a reverse shipment travels the other way, and a code
 * replaced on its line no longer travels at all. Correios Automático tells them apart since 1.11.0; before that, every
 * code goes, as its REST field does.
 *
 * Features are detected by the classes and methods that implement them, never by version numbers.
 *
 * @package Infixs\SquidCore
 * @since   1.5.0
 */
class CorreiosTrackingCodes {

	/**
	 * Service container of the free plugin, which hands out its tracking service.
	 */
	private const CONTAINER_CLASS = 'Infixs\\CorreiosAutomatico\\Container';

	/**
	 * Fired by Correios Automático after it adds a tracking code to an order (code, order id).
	 */
	public const ADDED_ACTION = 'infixs_correios_automatico_after_add_tracking_code';

	/**
	 * The filter Correios Automático applies to its REST field, applied here too so both answers stay the same.
	 */
	private const REST_FILTER = 'infixs_correios_automatico_rest_shop_order_object_tracking_codes';

	/**
	 * @var CorreiosAutomatico
	 */
	protected $correios;

	public function __construct( CorreiosAutomatico $correios ) {
		$this->correios = $correios;
	}

	/**
	 * Whether Correios Automático is there to tell the tracking codes of an order.
	 */
	public function isAvailable(): bool {
		return $this->correios->isActive() && method_exists( self::CONTAINER_CLASS, 'trackingService' );
	}

	/**
	 * The tracking codes of an order, or null when Correios Automático cannot tell them (then SquidCore leaves the
	 * shipments of the order as they are, instead of reading "no code").
	 *
	 * @param int $orderId
	 *
	 * @return array<int, array{id: mixed, code: string, carrier: string, description: string, category: string, date_created_gmt: mixed}>|null
	 */
	public function forOrder( int $orderId ): ?array {
		$service = $this->trackingService();

		if ( ! $service ) {
			return null;
		}

		$trackings = $service->getTrackings( $orderId );
		$outbound = $this->outboundCodes( $service, $orderId );
		$codes = [];

		foreach ( $trackings ?: [] as $track ) {
			if ( null !== $outbound && ! in_array( $track->code, $outbound, true ) ) {
				continue;
			}

			$codes[] = [
				'id' => $track->id,
				'code' => $track->code,
				'carrier' => ! empty( $track->carrier ) ? $track->carrier : 'correios',
				'description' => $track->description ?? '',
				'category' => $track->category ?? '',
				'date_created_gmt' => wc_rest_prepare_date_response( $track->created_at ),
			];
		}

		return apply_filters( self::REST_FILTER, $codes );
	}

	/**
	 * @return object|null The tracking service of Correios Automático, when it lists the codes of an order.
	 */
	protected function trackingService() {
		if ( ! $this->isAvailable() ) {
			return null;
		}

		$service = call_user_func( [ self::CONTAINER_CLASS, 'trackingService' ] );

		return is_object( $service ) && method_exists( $service, 'getTrackings' ) ? $service : null;
	}

	/**
	 * The codes of the objects travelling to the customer, or null when Correios Automático does not tell them apart.
	 *
	 * @param object $service
	 * @param int $orderId
	 *
	 * @return string[]|null
	 */
	protected function outboundCodes( $service, int $orderId ): ?array {
		if ( ! method_exists( $service, 'getShipmentCodes' ) ) {
			return null;
		}

		return array_values( array_filter( array_map( 'strval', wp_list_pluck( (array) $service->getShipmentCodes( $orderId ), 'code' ) ) ) );
	}
}
