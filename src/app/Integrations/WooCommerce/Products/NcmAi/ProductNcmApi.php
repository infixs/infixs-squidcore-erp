<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

use Infixs\SquidCore\Support\SquidCoreClient;

defined( 'ABSPATH' ) || exit;

/**
 * NCM endpoints of the SquidCore API for the products of the store, identified by their WooCommerce id: the AI
 * suggestion for one product (nothing saved) and the AI filling of the products without NCM (saved in SquidCore only
 * where it has none).
 *
 * One attempt per request: a retry would ask the AI again, on the store's key. Both wait longer than the other calls,
 * since the AI answers in seconds per product.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class ProductNcmApi {

	private const BASE = 'integrations/apps/woocommerce/products';

	/**
	 * Products SquidCore fills per call.
	 */
	public const FILL_MAX = 20;

	/**
	 * Seconds to wait for the suggestion of one product and for the filling of FILL_MAX products (asked a few at a
	 * time by SquidCore).
	 */
	public const SUGGEST_TIMEOUT = 60;

	public const FILL_TIMEOUT = 120;

	/**
	 * SquidCore codes of the answers.
	 */
	public const NOT_SYNCED = 'WOO_PRODUCT_NOT_SYNCED';

	public const AI_NOT_CONNECTED = 'AI_PROVIDER_NOT_CONNECTED';

	public const SUGGESTION_INVALID = 'NCM_SUGGESTION_INVALID';

	/**
	 * @var SquidCoreClient
	 */
	protected $client;

	public function __construct( SquidCoreClient $client ) {
		$this->client = $client->withMaxAttempts( 1 );
	}

	/**
	 * What the AI suggests for the product. Nothing is saved, in SquidCore or here.
	 *
	 * @return array|\WP_Error {wcProductId, productId, ncm, descricao, confianca, justificativa, squidcoreNcm (the NCM
	 *                         the product already has in SquidCore, null without one)}; 404
	 *                         WOO_PRODUCT_NOT_SYNCED while SquidCore does not know the product, 409
	 *                         AI_PROVIDER_NOT_CONNECTED, 422 NCM_SUGGESTION_INVALID, 502 when the AI did not answer.
	 */
	public function suggest( int $wcProductId ) {
		return $this->data( $this->client->withTimeout( self::SUGGEST_TIMEOUT )->post( self::BASE . '/' . $wcProductId . '/ncm-suggestion' ) );
	}

	/**
	 * Fills with the AI the NCM of the products SquidCore has without one (FILL_MAX at most).
	 *
	 * @param int[] $wcProductIds WooCommerce product ids.
	 *
	 * @return array|\WP_Error {filled: [{wcProductId, productId, ncm, descricao, confianca}], failed: [{wcProductId,
	 *                         reason, message}]}; 409 AI_PROVIDER_NOT_CONNECTED.
	 */
	public function fill( array $wcProductIds ) {
		return $this->data( $this->client->withTimeout( self::FILL_TIMEOUT )->post( self::BASE . '/ncm/fill', [
			'wcProductIds' => array_values( array_map( 'intval', $wcProductIds ) ),
		] ) );
	}

	/**
	 * @param array|\WP_Error $response
	 *
	 * @return array|\WP_Error
	 */
	private function data( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : [];
	}
}
