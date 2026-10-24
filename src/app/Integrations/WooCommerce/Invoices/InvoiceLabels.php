<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Texts of the invoice states and attention reasons, shared by notes, screens and the REST API.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceLabels {

	public static function state( string $state ): string {
		switch ( $state ) {
			case InvoiceRecord::SCHEDULED:
				return __( 'Scheduled', 'infixs-squidcore-erp' );
			case InvoiceRecord::EMITTING:
				return __( 'Sending', 'infixs-squidcore-erp' );
			case InvoiceRecord::PROCESSING:
				return __( 'Processing', 'infixs-squidcore-erp' );
			case InvoiceRecord::AUTHORIZED:
				return __( 'Authorized', 'infixs-squidcore-erp' );
			case InvoiceRecord::BLOCKED:
				return __( 'Pending items', 'infixs-squidcore-erp' );
			case InvoiceRecord::ERROR:
				return __( 'Rejected', 'infixs-squidcore-erp' );
			case InvoiceRecord::DENIED:
				return __( 'Denied', 'infixs-squidcore-erp' );
			case InvoiceRecord::FAILED:
				return __( 'Failed', 'infixs-squidcore-erp' );
			case InvoiceRecord::CANCELLED:
				return __( 'Cancelled', 'infixs-squidcore-erp' );
			case InvoiceRecord::SKIPPED:
				return __( 'Not issued', 'infixs-squidcore-erp' );
		}

		return __( 'No invoice', 'infixs-squidcore-erp' );
	}

	/**
	 * Date and time in the format and time zone of the site.
	 *
	 * @param int $timestamp Unix time.
	 */
	public static function dateTime( int $timestamp ): string {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * What the store owner has to do about an authorized invoice.
	 */
	public static function attention( string $reason ): string {
		switch ( $reason ) {
			case InvoiceRecord::ATTENTION_ORDER_CANCELLED:
				return __( 'The order was cancelled but its invoice is authorized. Cancel the invoice (up to 24 hours after the authorization) or issue a return invoice (NF-e de devolução) in SquidCore.', 'infixs-squidcore-erp' );
			case InvoiceRecord::ATTENTION_ORDER_REFUNDED:
				return __( 'The order was refunded but its invoice is authorized. Cancel the invoice (up to 24 hours after the authorization) or issue a return invoice (NF-e de devolução) in SquidCore.', 'infixs-squidcore-erp' );
			case InvoiceRecord::ATTENTION_PARTIAL_REFUND:
				return __( 'The order was partially refunded. Issue a partial return invoice (NF-e de devolução) in SquidCore.', 'infixs-squidcore-erp' );
			case InvoiceRecord::ATTENTION_PREPOST_USES_CANCELLED_INVOICE:
				return __( 'The Correios pre-posting of this order was created with the invoice that was cancelled. Cancel the pre-posting in Correios Automático and create it again with the new invoice.', 'infixs-squidcore-erp' );
		}

		return '';
	}

	/**
	 * Notice for the result of filling the missing NCM with AI.
	 *
	 * @param array $result {filled: array, failed: array, retried: bool}
	 *
	 * @return array{type: string, message: string}
	 */
	public static function ncmFillResult( array $result ): array {
		$filled = count( (array) ( $result['filled'] ?? [] ) );
		$failed = count( (array) ( $result['failed'] ?? [] ) );

		if ( $filled === 0 ) {
			return [
				'type' => 'error',
				'message' => __( 'The AI could not suggest an NCM for the products. Fill it in SquidCore and try again.', 'infixs-squidcore-erp' ),
			];
		}

		$message = sprintf(
			/* translators: %d: number of products. */
			_n( 'NCM filled by AI for %d product. Review it in SquidCore.', 'NCM filled by AI for %d products. Review them in SquidCore.', $filled, 'infixs-squidcore-erp' ),
			$filled
		);

		if ( ! empty( $result['retried'] ) ) {
			$message .= ' ' . __( 'The invoice was requested again.', 'infixs-squidcore-erp' );
		}

		if ( $failed > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of products. */
				_n( '%d product is still without NCM: fill it in SquidCore, then click "Try again".', '%d products are still without NCM: fill them in SquidCore, then click "Try again".', $failed, 'infixs-squidcore-erp' ),
				$failed
			);
		}

		return [
			'type' => $failed > 0 ? 'warning' : 'success',
			'message' => $message,
		];
	}

	/**
	 * Order note listing the products that got an NCM from the AI of SquidCore, blank when none.
	 *
	 * @param array[] $filled Products filled by the AI ({wcProductId, ncm, descricao}).
	 */
	public static function ncmFilledNote( array $filled ): string {
		$lines = [];

		foreach ( $filled as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$ncm = sanitize_text_field( (string) ( $item['ncm'] ?? '' ) );

			if ( $ncm === '' ) {
				continue;
			}

			$product_id = (int) ( $item['wcProductId'] ?? 0 );
			$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			$name = $product instanceof \WC_Product ? sanitize_text_field( $product->get_name() ) : ( $product_id > 0 ? '#' . $product_id : __( 'Product', 'infixs-squidcore-erp' ) );
			$description = sanitize_text_field( (string) ( $item['descricao'] ?? '' ) );

			$lines[] = $description !== ''
				/* translators: 1: product name, 2: NCM code, 3: NCM description. */
				? sprintf( __( '%1$s: NCM %2$s (%3$s)', 'infixs-squidcore-erp' ), $name, $ncm, $description )
				/* translators: 1: product name, 2: NCM code. */
				: sprintf( __( '%1$s: NCM %2$s', 'infixs-squidcore-erp' ), $name, $ncm );
		}

		if ( ! $lines ) {
			return '';
		}

		return __( 'SquidCore: NCM filled by AI (review it in SquidCore):', 'infixs-squidcore-erp' ) . "\n- " . implode( "\n- ", $lines );
	}
}
