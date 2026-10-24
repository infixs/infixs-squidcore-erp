<?php
/**
 * Invoice links of the customer emails (HTML).
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
?>
<div class="infixs-squidcore-email-invoice" style="margin-bottom: 40px;">
	<h2><?php esc_html_e( 'Invoice', 'infixs-squidcore-erp' ); ?></h2>
	<p>
		<?php
		/* translators: 1: invoice number, 2: invoice series. */
		echo esc_html( sprintf( __( 'NF-e number %1$s, series %2$s', 'infixs-squidcore-erp' ), $number, $series ) );
		?>
	</p>
	<p>
		<a href="<?php echo esc_url( $danfe_url ); ?>"><?php esc_html_e( 'Download the DANFE (PDF)', 'infixs-squidcore-erp' ); ?></a>
		&nbsp;|&nbsp;
		<a href="<?php echo esc_url( $xml_url ); ?>"><?php esc_html_e( 'Download the XML', 'infixs-squidcore-erp' ); ?></a>
	</p>
</div>
