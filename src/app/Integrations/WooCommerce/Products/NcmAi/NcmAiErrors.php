<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

use Infixs\SquidCore\Support\ApiErrorMessage;

defined( 'ABSPATH' ) || exit;

/**
 * Errors of the AI NCM features (the suggestion of the product editor and the bulk filling), with the message the store
 * owner reads, ready for the plugin's REST API.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
final class NcmAiErrors {

	public const NOT_CONNECTED = 'ncm_ai_not_connected';

	public const UNAVAILABLE = 'ncm_ai_unavailable';

	public const UNSUPPORTED = 'ncm_ai_unsupported';

	public const NOT_SAVED = 'ncm_ai_product_not_saved';

	public const NOT_SYNCED = 'ncm_ai_not_synced';

	public const CATALOG_OFF = 'ncm_ai_catalog_off';

	public const INVALID = 'ncm_ai_invalid';

	public const TEMPORARY = 'ncm_ai_temporary';

	public const FAILED = 'ncm_ai_failed';

	public const PRODUCT_NOT_FOUND = 'ncm_ai_product_not_found';

	public const BUSY = 'ncm_ai_fill_busy';

	public const NOTHING = 'ncm_ai_fill_nothing';

	public const RUN_NOT_FOUND = 'ncm_ai_fill_not_found';

	public const INVALID_STATE = 'ncm_ai_fill_invalid_state';

	/**
	 * Why the store cannot ask the AI now: NOT_CONNECTED, UNSUPPORTED or UNAVAILABLE; null when it can.
	 */
	public static function unavailability( NcmAiAvailability $ai ): ?string {
		if ( ! $ai->isConnected() ) {
			return self::NOT_CONNECTED;
		}

		if ( $ai->isUnsupported() ) {
			return self::UNSUPPORTED;
		}

		return $ai->isAvailable() ? null : self::UNAVAILABLE;
	}

	/**
	 * What the store owner reads for any other refusal of SquidCore: the integration turned off has its own message,
	 * the rest is read as every request of the plugin (token, permission, network).
	 */
	public static function describe( \WP_Error $error ): string {
		return ApiErrorMessage::code( $error ) === 'WOO_INTEGRATION_NOT_ACTIVE' ? ApiErrorMessage::describeInvoice( $error ) : ApiErrorMessage::describe( $error );
	}

	public static function make( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, self::message( $code ), [ 'status' => $status ] );
	}

	public static function message( string $code ): string {
		switch ( $code ) {
			case self::NOT_CONNECTED:
				return __( 'This store is not connected to SquidCore. Connect it to fill the NCM with AI.', 'infixs-squidcore-erp' );
			case self::UNAVAILABLE:
				return __( 'No AI is connected in SquidCore. Connect OpenAI or Claude in Integrations in SquidCore to fill the NCM with AI.', 'infixs-squidcore-erp' );
			case self::UNSUPPORTED:
				return __( 'This SquidCore version does not fill the NCM of store products yet. Try again later.', 'infixs-squidcore-erp' );
			case self::NOT_SAVED:
				return __( 'Save the product first (a draft is enough): the AI reads its saved name and description.', 'infixs-squidcore-erp' );
			case self::NOT_SYNCED:
				return NcmAiLabels::failure( NcmAiLabels::NOT_SYNCED );
			case self::CATALOG_OFF:
				return NcmAiLabels::failure( NcmAiLabels::CATALOG_OFF );
			case self::INVALID:
				return NcmAiLabels::failure( NcmAiLabels::INVALID );
			case self::TEMPORARY:
				return __( 'The AI did not answer now. Try again in a few moments.', 'infixs-squidcore-erp' );
			case self::PRODUCT_NOT_FOUND:
				return __( 'Product not found.', 'infixs-squidcore-erp' );
			case self::BUSY:
				return __( 'The NCM is already being filled with AI. Wait for it to finish or stop it.', 'infixs-squidcore-erp' );
			case self::NOTHING:
				return __( 'No product is without NCM: there is nothing for the AI to fill.', 'infixs-squidcore-erp' );
			case self::RUN_NOT_FOUND:
				return __( 'This filling was not found. Refresh the page.', 'infixs-squidcore-erp' );
			case self::INVALID_STATE:
				return __( 'This filling can no longer do that. Refresh the page.', 'infixs-squidcore-erp' );
		}

		return __( 'The NCM could not be filled with AI. Try again.', 'infixs-squidcore-erp' );
	}
}
