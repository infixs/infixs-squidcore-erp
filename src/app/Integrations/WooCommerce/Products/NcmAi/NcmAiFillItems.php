<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCode;

defined( 'ABSPATH' ) || exit;

/**
 * A row of the lists of the AI filling as the admin app reads it (TS NcmAiFillItem): the product with its current name
 * and edit link, the NCM the AI filled with its official description and confidence, or why it was not filled.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
final class NcmAiFillItems {

	/**
	 * @param array  $row  Stored row (NcmAiFillBatch).
	 * @param string $list NcmAiFillRepository::FILLED or FAILED.
	 */
	public static function present( array $row, string $list ): array {
		$product_id = (int) ( $row['productId'] ?? 0 );
		$name = $product_id > 0 ? (string) get_post_field( 'post_title', $product_id ) : '';
		$filled = $list === NcmAiFillRepository::FILLED;
		$reason = $filled ? null : ( in_array( $row['reason'] ?? '', NcmAiLabels::REASONS, true ) ? (string) $row['reason'] : NcmAiLabels::FAILED );

		return [
			'productId' => $product_id,
			'name' => $name !== '' ? sanitize_text_field( $name ) : '#' . $product_id,
			'editUrl' => admin_url( 'post.php?post=' . $product_id . '&action=edit' ),
			'ncm' => $filled ? NcmCode::format( NcmCode::normalize( (string) ( $row['ncm'] ?? '' ) ) ) : '',
			'description' => $filled ? sanitize_text_field( (string) ( $row['descricao'] ?? '' ) ) : '',
			'confidence' => $filled ? NcmAiLabels::confidence( $row['confianca'] ?? null ) : null,
			'source' => $filled ? ( ( $row['source'] ?? '' ) === NcmAiFillBatch::SOURCE_SQUIDCORE ? NcmAiFillBatch::SOURCE_SQUIDCORE : NcmAiFillBatch::SOURCE_AI ) : null,
			'reason' => $reason,
			'message' => $reason !== null ? NcmAiLabels::failure( $reason, sanitize_text_field( (string) ( $row['message'] ?? '' ) ) ) : null,
		];
	}
}
