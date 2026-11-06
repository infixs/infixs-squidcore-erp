<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCode;
use Infixs\SquidCore\Integrations\WooCommerce\SyncDispatcher;
use Infixs\SquidCore\Services\SyncService;
use Infixs\SquidCore\Support\ApiErrorMessage;

defined( 'ABSPATH' ) || exit;

/**
 * "Suggest with AI" of the NCM field of the product editor: what the AI of SquidCore suggests for the product, saved
 * nowhere. The store owner reviews it in the field and saves the product as usual.
 *
 * A product SquidCore does not know yet is sent to it first (the push the plugin always uses), while the catalog sync
 * is on. When the product in SquidCore already has an NCM, that one is offered instead of the AI's: SquidCore keeps it
 * on the invoices whatever the store sends later, so the product here and Correios Automático get the same NCM as the
 * NF-e; the AI's, when different, is only told. Errors come back as WP_Error with the REST status; a refusal of
 * SquidCore never passes as 401 or 403, which WordPress would read as a problem with the logged-in user.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class ProductNcmSuggestion {

	/**
	 * @var ProductNcmApi
	 */
	protected $api;

	/**
	 * @var NcmAiAvailability
	 */
	protected $ai;

	/**
	 * @var SyncDispatcher
	 */
	protected $dispatcher;

	/**
	 * @var SyncService
	 */
	protected $sync;

	public function __construct( ProductNcmApi $api, NcmAiAvailability $ai, SyncDispatcher $dispatcher, SyncService $sync ) {
		$this->api = $api;
		$this->ai = $ai;
		$this->dispatcher = $dispatcher;
		$this->sync = $sync;
	}

	/**
	 * Where the NCM offered comes from: the AI, or SquidCore, which already uses one on the invoices of the product.
	 */
	public const SOURCE_AI = NcmAiFillBatch::SOURCE_AI;

	public const SOURCE_SQUIDCORE = NcmAiFillBatch::SOURCE_SQUIDCORE;

	/**
	 * @param \WC_Product $product Product (a variation stands for its parent).
	 *
	 * @return array|\WP_Error {wcProductId, ncm (digits), formatted, description, confidence (high, medium, low or
	 *                         null), justification, source (SOURCE_AI or SOURCE_SQUIDCORE), message}
	 */
	public function suggest( \WC_Product $product ) {
		if ( $product->get_parent_id() > 0 ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$product = $parent instanceof \WC_Product ? $parent : $product;
		}

		$unavailable = NcmAiErrors::unavailability( $this->ai );

		if ( $unavailable !== null ) {
			return NcmAiErrors::make( $unavailable, 409 );
		}

		// The AI reads the product SquidCore keeps: a product never saved has nothing to read yet.
		if ( $product->get_status() === 'auto-draft' ) {
			return NcmAiErrors::make( NcmAiErrors::NOT_SAVED, 409 );
		}

		$response = $this->api->suggest( $product->get_id() );

		if ( is_wp_error( $response ) && ApiErrorMessage::code( $response ) === ProductNcmApi::NOT_SYNCED ) {
			$pushed = $this->push( $product );

			if ( is_wp_error( $pushed ) ) {
				return $pushed;
			}

			$response = $this->api->suggest( $product->get_id() );
		}

		if ( is_wp_error( $response ) ) {
			return $this->translate( $response );
		}

		$digits = NcmCode::normalize( (string) ( $response['ncm'] ?? '' ) );
		$squidcore = NcmCode::normalize( is_scalar( $response['squidcoreNcm'] ?? null ) ? (string) $response['squidcoreNcm'] : '' );
		$valid = NcmCode::isValid( $digits );
		$known = NcmCode::isValid( $squidcore );

		if ( ! $valid && ! $known ) {
			return NcmAiErrors::make( NcmAiErrors::INVALID, 422 );
		}

		$description = sanitize_text_field( (string) ( $response['descricao'] ?? '' ) );
		$confidence = NcmAiLabels::confidence( $response['confianca'] ?? null );
		$justification = sanitize_textarea_field( (string) ( $response['justificativa'] ?? '' ) );

		if ( $known ) {
			return $this->squidcoreSuggestion( $product->get_id(), $squidcore, $valid ? $digits : '', $description, $confidence, $justification );
		}

		return [
			'wcProductId' => $product->get_id(),
			'ncm' => $digits,
			'formatted' => NcmCode::format( $digits ),
			'description' => $description,
			'confidence' => $confidence,
			'justification' => $justification,
			'source' => self::SOURCE_AI,
			'message' => self::message( NcmCode::format( $digits ), $description, $confidence ),
		];
	}

	/**
	 * What the field says under the suggestion.
	 */
	public static function message( string $formatted, string $description, ?string $confidence ): string {
		/* translators: %s: suggested NCM with its description, e.g. "3406.00.00 — Velas, pavios e semelhantes (high confidence)". */
		return sprintf( __( 'AI suggestion: %s. Review it and save the product to keep it.', 'infixs-squidcore-erp' ), self::label( $formatted, $description, $confidence ) );
	}

	/**
	 * The NCM SquidCore already uses on the invoices of the product, offered in place of the AI's.
	 *
	 * @param string $ai Digits of the AI suggestion ('' when it gave none valid).
	 */
	private function squidcoreSuggestion( int $wcProductId, string $squidcore, string $ai, string $description, ?string $confidence, string $justification ): array {
		$same = $ai === $squidcore;
		$formatted = NcmCode::format( $squidcore );

		if ( $same ) {
			/* translators: %s: NCM with its description, e.g. "3406.00.00 — Velas, pavios e semelhantes (high confidence)". */
			$message = sprintf( __( 'SquidCore already uses this NCM on the invoices of this product, and the AI agrees: %s. Save the product to keep it here too.', 'infixs-squidcore-erp' ), self::label( $formatted, $description, $confidence ) );
		} elseif ( $ai !== '' ) {
			$message = sprintf(
				/* translators: 1: NCM SquidCore uses, e.g. 6109.10.00; 2: NCM the AI suggested with its description, e.g. "3406.00.00 — Velas (high confidence)". */
				__( 'SquidCore already uses the NCM %1$s on the invoices of this product and keeps it whatever is saved here, so it went into the field. The AI suggested %2$s: to use that one, change the NCM of the product in SquidCore.', 'infixs-squidcore-erp' ),
				$formatted,
				self::label( NcmCode::format( $ai ), $description, $confidence )
			);
		} else {
			/* translators: %s: NCM SquidCore uses, e.g. 6109.10.00. */
			$message = sprintf( __( 'SquidCore already uses the NCM %s on the invoices of this product, so it went into the field. Save the product to keep it here too.', 'infixs-squidcore-erp' ), $formatted );
		}

		return [
			'wcProductId' => $wcProductId,
			'ncm' => $squidcore,
			'formatted' => $formatted,
			'description' => $same ? $description : '',
			'confidence' => $same ? $confidence : null,
			'justification' => $same ? $justification : '',
			'source' => self::SOURCE_SQUIDCORE,
			'message' => $message,
		];
	}

	/**
	 * "3406.00.00 — Velas (high confidence)", without the parts SquidCore did not tell.
	 */
	private static function label( string $formatted, string $description, ?string $confidence ): string {
		$label = NcmAiLabels::confidenceLabel( $confidence );
		$text = $description !== '' ? $formatted . ' — ' . $description : $formatted;

		return $label !== '' ? $text . ' (' . $label . ')' : $text;
	}

	/**
	 * Sends the product to SquidCore now, while the catalog sync is on.
	 *
	 * @return true|\WP_Error
	 */
	private function push( \WC_Product $product ) {
		if ( ! $this->sync->options()['catalog'] ) {
			return NcmAiErrors::make( NcmAiErrors::CATALOG_OFF, 409 );
		}

		$pushed = $this->dispatcher->pushNow( 'product.updated', $product->get_id() );

		if ( $pushed === true ) {
			return true;
		}

		return new \WP_Error(
			NcmAiErrors::NOT_SYNCED,
			/* translators: %s: why the product could not be sent. */
			sprintf( __( 'The product could not be sent to SquidCore, so the AI cannot read it: %s', 'infixs-squidcore-erp' ), ApiErrorMessage::describe( $pushed ) ),
			[ 'status' => 409 ]
		);
	}

	/**
	 * The failed suggestion as an error of the plugin's REST API.
	 */
	private function translate( \WP_Error $error ): \WP_Error {
		if ( $this->ai->noteRouteMissing( $error ) ) {
			return NcmAiErrors::make( NcmAiErrors::UNSUPPORTED, 409 );
		}

		switch ( ApiErrorMessage::code( $error ) ) {
			case ProductNcmApi::AI_NOT_CONNECTED:
				return NcmAiErrors::make( NcmAiErrors::UNAVAILABLE, 409 );
			case ProductNcmApi::NOT_SYNCED:
				return NcmAiErrors::make( NcmAiErrors::NOT_SYNCED, 409 );
			case ProductNcmApi::SUGGESTION_INVALID:
				return NcmAiErrors::make( NcmAiErrors::INVALID, 422 );
		}

		if ( ApiErrorMessage::isTransient( $error ) ) {
			return NcmAiErrors::make( NcmAiErrors::TEMPORARY, 502 );
		}

		if ( ApiErrorMessage::status( $error ) === 422 ) {
			return NcmAiErrors::make( NcmAiErrors::INVALID, 422 );
		}

		return new \WP_Error( NcmAiErrors::FAILED, NcmAiErrors::describe( $error ), [
			'status' => 422,
			'upstreamStatus' => ApiErrorMessage::status( $error ),
			'upstreamCode' => ApiErrorMessage::code( $error ),
		] );
	}
}
