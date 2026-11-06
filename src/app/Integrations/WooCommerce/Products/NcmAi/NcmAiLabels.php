<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

defined( 'ABSPATH' ) || exit;

/**
 * Why the AI did not fill the NCM of a product, and how sure it was, as the store owner reads them.
 *
 * SquidCore names the reasons of the product filling (not_synced, has_ncm, invalid, failed, limit_exceeded) and,
 * before it, the ones of the order filling (product_not_found, ncm_present, ncm_suggestion_invalid, ai_unavailable…);
 * both come down to the reasons below. The plugin adds its own: the catalog sync is off, the product got an NCM here
 * meanwhile, the product is gone.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
final class NcmAiLabels {

	public const NOT_SYNCED = 'not_synced';

	public const CATALOG_OFF = 'catalog_off';

	public const HAS_NCM = 'has_ncm';

	public const INVALID = 'invalid';

	public const FAILED = 'failed';

	public const LIMIT_EXCEEDED = 'limit_exceeded';

	public const CHANGED = 'changed';

	public const GONE = 'gone';

	public const REASONS = [ self::NOT_SYNCED, self::CATALOG_OFF, self::HAS_NCM, self::INVALID, self::FAILED, self::LIMIT_EXCEEDED, self::CHANGED, self::GONE ];

	/**
	 * Codes of SquidCore => reason.
	 */
	private const ALIASES = [
		'not_synced' => self::NOT_SYNCED,
		'product_not_found' => self::NOT_SYNCED,
		'product_not_in_order' => self::NOT_SYNCED,
		'has_ncm' => self::HAS_NCM,
		'ncm_present' => self::HAS_NCM,
		'invalid' => self::INVALID,
		'ncm_suggestion_invalid' => self::INVALID,
		'failed' => self::FAILED,
		'ai_unavailable' => self::FAILED,
		'limit_exceeded' => self::LIMIT_EXCEEDED,
	];

	/**
	 * Confidence of SquidCore => the one the screens show.
	 */
	private const CONFIDENCES = [
		'alta' => 'high',
		'high' => 'high',
		'media' => 'medium',
		'média' => 'medium',
		'medium' => 'medium',
		'baixa' => 'low',
		'low' => 'low',
	];

	/**
	 * The reason of a failure SquidCore told: its `code` when it has one (the order filling, where `reason` is the
	 * message), otherwise its `reason`. Unknown codes are failures.
	 */
	public static function reasonOf( array $failure ): string {
		$code = $failure['code'] ?? ( $failure['reason'] ?? '' );
		$code = is_string( $code ) ? strtolower( trim( $code ) ) : '';

		return self::ALIASES[ $code ] ?? self::FAILED;
	}

	/**
	 * The message SquidCore wrote for a failure, blank when none.
	 */
	public static function messageOf( array $failure ): string {
		$message = isset( $failure['code'] ) ? ( $failure['reason'] ?? ( $failure['message'] ?? '' ) ) : ( $failure['message'] ?? '' );

		return is_string( $message ) ? sanitize_text_field( $message ) : '';
	}

	/**
	 * What the store owner reads for a reason.
	 *
	 * @param string $fallback Message SquidCore wrote, for a failure the plugin does not describe better.
	 */
	public static function failure( string $reason, string $fallback = '' ): string {
		switch ( $reason ) {
			case self::NOT_SYNCED:
				return $fallback !== '' ? $fallback : __( 'The product has not reached SquidCore yet. Save it once and try again.', 'infixs-squidcore-erp' );
			case self::CATALOG_OFF:
				return __( 'The product has not reached SquidCore and the catalog sync is off. Turn on "Sync catalog" in the plugin settings, or fill the NCM in by hand.', 'infixs-squidcore-erp' );
			case self::HAS_NCM:
				return __( 'SquidCore already has an NCM for this product, which was kept. Copy it to the product here or review it in SquidCore.', 'infixs-squidcore-erp' );
			case self::INVALID:
				return __( 'The AI did not find a valid NCM of the official table for this product. Fill it in by hand.', 'infixs-squidcore-erp' );
			case self::LIMIT_EXCEEDED:
				return __( 'Left out of this batch by SquidCore. Run the filling again for the products still without NCM.', 'infixs-squidcore-erp' );
			case self::CHANGED:
				return __( 'The product got an NCM here while the AI answered: that NCM was kept.', 'infixs-squidcore-erp' );
			case self::GONE:
				return __( 'The product is no longer in the store.', 'infixs-squidcore-erp' );
		}

		return __( 'The AI did not answer for this product. Try again later.', 'infixs-squidcore-erp' );
	}

	/**
	 * high, medium or low; null when SquidCore did not say (or said something else).
	 *
	 * @param mixed $confidence
	 */
	public static function confidence( $confidence ): ?string {
		$key = is_string( $confidence ) ? strtolower( trim( $confidence ) ) : '';

		return self::CONFIDENCES[ $key ] ?? null;
	}

	/**
	 * The confidence as a word, blank when unknown.
	 *
	 * @param string|null $confidence Output of confidence().
	 */
	public static function confidenceLabel( ?string $confidence ): string {
		switch ( $confidence ) {
			case 'high':
				return __( 'high confidence', 'infixs-squidcore-erp' );
			case 'medium':
				return __( 'medium confidence', 'infixs-squidcore-erp' );
			case 'low':
				return __( 'low confidence', 'infixs-squidcore-erp' );
		}

		return '';
	}
}
