<?php
/**
 * Invoice section of the order details (My Account and thank-you page).
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

// Block themes style buttons through their own class, as the WooCommerce templates do.
$infixs_squidcore_button_class = function_exists( 'wc_wp_theme_get_element_class_name' ) && wc_wp_theme_get_element_class_name( 'button' )
	? ' ' . wc_wp_theme_get_element_class_name( 'button' )
	: '';
?>
<section class="woocommerce-order-invoice infixs-squidcore-order-invoice">
	<h2 class="woocommerce-order-invoice__title"><?php esc_html_e( 'Invoice', 'infixs-squidcore-erp' ); ?></h2>
	<p>
		<?php
		/* translators: 1: invoice number, 2: invoice series. */
		echo esc_html( sprintf( __( 'NF-e number %1$s, series %2$s', 'infixs-squidcore-erp' ), $number, $series ) );
		?>
	</p>
	<p class="infixs-squidcore-order-invoice__actions" style="display: flex; flex-wrap: wrap; gap: 0.5em;">
		<a class="woocommerce-button button<?php echo esc_attr( $infixs_squidcore_button_class ); ?>" href="<?php echo esc_url( $danfe_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'DANFE (PDF)', 'infixs-squidcore-erp' ); ?></a>
		<a class="woocommerce-button button<?php echo esc_attr( $infixs_squidcore_button_class ); ?>" href="<?php echo esc_url( $xml_url ); ?>" rel="noreferrer"><?php esc_html_e( 'XML', 'infixs-squidcore-erp' ); ?></a>
	</p>
</section>
