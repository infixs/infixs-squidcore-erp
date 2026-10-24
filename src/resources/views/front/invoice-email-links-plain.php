<?php
/**
 * Invoice links of the customer emails (plain text).
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 *
 * @var string $number    Invoice number.
 * @var string $series    Invoice series.
 * @var string $danfe_url Customer DANFE download.
 * @var string $xml_url   Customer XML download.
 */

defined( 'ABSPATH' ) || exit;

echo "\n" . esc_html( wc_strtoupper( __( 'Invoice', 'infixs-squidcore-erp' ) ) ) . "\n\n";
/* translators: 1: invoice number, 2: invoice series. */
echo esc_html( sprintf( __( 'NF-e number %1$s, series %2$s', 'infixs-squidcore-erp' ), $number, $series ) ) . "\n";
echo esc_html__( 'DANFE (PDF)', 'infixs-squidcore-erp' ) . ': ' . esc_url( $danfe_url ) . "\n";
echo esc_html__( 'XML', 'infixs-squidcore-erp' ) . ': ' . esc_url( $xml_url ) . "\n\n";
