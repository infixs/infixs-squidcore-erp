<?php
/**
 * Content of the "Invoice (NF-e)" box of the order edit screen. Also returned by the order invoice REST routes, so
 * the box script can swap it after each action.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 *
 * @var array  $invoice         InvoicePresenter::present().
 * @var array  $notice          Optional {type, message} shown on top.
 * @var bool   $is_new_order    The order was not saved yet.
 * @var string $emit_label      Label of the emission button.
 * @var string $emit_confirm    Confirmation asked before the emission, blank for none.
 * @var string $scheduled_text  Date of the scheduled emission.
 * @var string $deadline_text   Last moment to cancel the invoice.
 * @var string $authorized_text Date of the authorization.
 * @var string $key_text        Access key in groups of four digits.
 * @var array  $reasons         Blocking reasons (InvoicePresenter): {message, productName, productEditUrl, ...}.
 * @var bool   $ai_fillable     The missing NCM can be filled by the AI of SquidCore.
 * @var bool   $ncm_hint        Show how to enable filling the NCM with AI.
 * @var string $ai_connect_url  Integrations page of the SquidCore panel, where an AI is connected; blank without the hint.
 * @var int    $min_length      Minimum length of the cancellation reason.
 * @var int    $max_length      Maximum length of the cancellation reason.
 * @var string $danfe_print_url   Simplified DANFE print of Correios Automático; blank when not offered.
 * @var string $danfe_status_text Whether the simplified DANFE is ready; blank when the invoice gets none.
 */

defined( 'ABSPATH' ) || exit;

$infixs_squidcore_state = $invoice['state'];
$infixs_squidcore_details = $invoice['invoice'];
?>
<?php if ( ! empty( $notice['message'] ) ) : ?>
	<div class="notice notice-<?php echo esc_attr( $notice['type'] ?? 'info' ); ?> inline isq-invoice__notice">
		<p><?php echo esc_html( $notice['message'] ); ?></p>
	</div>
<?php endif; ?>

<?php if ( $is_new_order ) : ?>
	<p class="isq-invoice__text"><?php esc_html_e( 'Save the order to issue its invoice.', 'infixs-squidcore-erp' ); ?></p>
	<?php return; ?>
<?php endif; ?>

<p class="isq-invoice__status">
	<span class="isq-invoice__badge isq-invoice__badge--<?php echo esc_attr( $infixs_squidcore_state ); ?>"><?php echo esc_html( $invoice['stateLabel'] ); ?></span>
	<?php if ( $infixs_squidcore_details && $infixs_squidcore_details['environment'] === 'homologation' ) : ?>
		<span class="isq-invoice__badge isq-invoice__badge--test"><?php esc_html_e( 'Test environment', 'infixs-squidcore-erp' ); ?></span>
	<?php endif; ?>
</p>

<?php if ( $invoice['attentionMessage'] !== '' ) : ?>
	<div class="notice notice-warning inline isq-invoice__attention">
		<p><?php echo esc_html( $invoice['attentionMessage'] ); ?></p>
	</div>
<?php endif; ?>

<?php if ( $infixs_squidcore_state === 'none' ) : ?>
	<p class="isq-invoice__text"><?php esc_html_e( 'No invoice was issued for this order yet.', 'infixs-squidcore-erp' ); ?></p>
<?php elseif ( $infixs_squidcore_state === 'scheduled' && $scheduled_text !== '' ) : ?>
	<p class="isq-invoice__text">
		<?php
		/* translators: %s: date and time of the emission. */
		echo esc_html( sprintf( __( 'Automatic emission scheduled for %s.', 'infixs-squidcore-erp' ), $scheduled_text ) );
		?>
	</p>
<?php elseif ( $infixs_squidcore_state === 'emitting' ) : ?>
	<p class="isq-invoice__text"><?php esc_html_e( 'Sending the invoice to SquidCore.', 'infixs-squidcore-erp' ); ?></p>
<?php elseif ( $infixs_squidcore_state === 'processing' ) : ?>
	<p class="isq-invoice__text">
		<?php
		echo esc_html( $invoice['stalled']
			? __( 'SEFAZ is taking longer than usual. Click "Refresh" to check again.', 'infixs-squidcore-erp' )
			: __( 'Waiting for SEFAZ to authorize the invoice.', 'infixs-squidcore-erp' ) );
		?>
	</p>
<?php endif; ?>

<?php if ( $infixs_squidcore_details && in_array( $infixs_squidcore_state, [ 'authorized', 'cancelled' ], true ) ) : ?>
	<dl class="isq-invoice__details">
		<dt><?php esc_html_e( 'Number / series', 'infixs-squidcore-erp' ); ?></dt>
		<dd><?php echo esc_html( $infixs_squidcore_details['number'] . ' / ' . $infixs_squidcore_details['series'] ); ?></dd>
		<?php if ( $key_text !== '' ) : ?>
			<dt><?php esc_html_e( 'Access key', 'infixs-squidcore-erp' ); ?></dt>
			<dd class="isq-invoice__key"><?php echo esc_html( $key_text ); ?></dd>
		<?php endif; ?>
		<?php if ( $authorized_text !== '' ) : ?>
			<dt><?php esc_html_e( 'Issued on', 'infixs-squidcore-erp' ); ?></dt>
			<dd><?php echo esc_html( $authorized_text ); ?></dd>
		<?php endif; ?>
	</dl>
	<?php if ( $infixs_squidcore_state === 'authorized' ) : ?>
		<p class="isq-invoice__hint">
			<?php
			echo esc_html( $invoice['canCancel'] && $deadline_text !== ''
				/* translators: %s: date and time. */
				? sprintf( __( 'Can be cancelled until %s.', 'infixs-squidcore-erp' ), $deadline_text )
				: __( 'The cancellation window has ended. To undo it, issue a return invoice (NF-e de devolução) in SquidCore.', 'infixs-squidcore-erp' ) );
			?>
		</p>
	<?php endif; ?>
	<?php if ( $danfe_status_text !== '' ) : ?>
		<p class="isq-invoice__hint isq-invoice__danfe"><?php echo esc_html( $danfe_status_text ); ?></p>
	<?php endif; ?>
<?php endif; ?>

<?php if ( $invoice['message'] !== '' && $infixs_squidcore_state !== 'authorized' ) : ?>
	<p class="isq-invoice__message"><?php echo esc_html( $invoice['message'] ); ?></p>
<?php endif; ?>

<?php if ( $reasons ) : ?>
	<ul class="isq-invoice__reasons">
		<?php foreach ( $reasons as $infixs_squidcore_reason ) : ?>
			<li>
				<?php echo esc_html( $infixs_squidcore_reason['message'] ); ?>
				<?php if ( $infixs_squidcore_reason['productEditUrl'] !== '' ) : ?>
					<a href="<?php echo esc_url( $infixs_squidcore_reason['productEditUrl'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php
						/* translators: %s: product name. */
						echo esc_html( sprintf( __( 'Edit %s', 'infixs-squidcore-erp' ), $infixs_squidcore_reason['productName'] ) );
						?>
					</a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<?php if ( $ncm_hint ) : ?>
	<p class="isq-invoice__hint">
		<?php esc_html_e( 'Tip: connect OpenAI or Claude in SquidCore to fill the missing NCM with AI.', 'infixs-squidcore-erp' ); ?>
		<?php if ( $ai_connect_url !== '' ) : ?>
			<a href="<?php echo esc_url( $ai_connect_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Connect an AI in SquidCore', 'infixs-squidcore-erp' ); ?></a>
		<?php endif; ?>
	</p>
<?php endif; ?>

<div class="isq-invoice__actions">
	<?php if ( $invoice['canEmit'] ) : ?>
		<button type="button" class="button button-primary" data-isq-action="emit"<?php echo $emit_confirm !== '' ? ' data-confirm="' . esc_attr( $emit_confirm ) . '"' : ''; ?>><?php echo esc_html( $emit_label ); ?></button>
	<?php endif; ?>
	<?php if ( $ai_fillable ) : ?>
		<button type="button" class="button" data-isq-action="fill-ncm"><?php esc_html_e( 'Fill NCM with AI', 'infixs-squidcore-erp' ); ?></button>
	<?php endif; ?>
	<?php if ( $invoice['downloads'] ) : ?>
		<a class="button" href="<?php echo esc_url( $invoice['downloads']['danfe'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'DANFE', 'infixs-squidcore-erp' ); ?></a>
		<a class="button" href="<?php echo esc_url( $invoice['downloads']['xml'] ); ?>"><?php esc_html_e( 'XML', 'infixs-squidcore-erp' ); ?></a>
		<?php if ( ! empty( $invoice['downloads']['cancellationXml'] ) ) : ?>
			<a class="button" href="<?php echo esc_url( $invoice['downloads']['cancellationXml'] ); ?>"><?php esc_html_e( 'Cancellation XML', 'infixs-squidcore-erp' ); ?></a>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( $danfe_print_url !== '' ) : ?>
		<a class="button" href="<?php echo esc_url( $danfe_print_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Simplified DANFE', 'infixs-squidcore-erp' ); ?></a>
	<?php endif; ?>
	<?php if ( $invoice['canRefresh'] ) : ?>
		<button type="button" class="button" data-isq-action="refresh"><?php esc_html_e( 'Refresh', 'infixs-squidcore-erp' ); ?></button>
	<?php endif; ?>
	<?php if ( $invoice['canCancel'] ) : ?>
		<button type="button" class="button button-link-delete isq-invoice__cancel-open" data-isq-action="cancel-open" aria-expanded="false"><?php esc_html_e( 'Cancel invoice', 'infixs-squidcore-erp' ); ?></button>
	<?php endif; ?>
	<span class="spinner isq-invoice__spinner"></span>
</div>

<?php if ( $invoice['canCancel'] ) : ?>
	<div class="isq-invoice__cancel" data-isq-cancel hidden>
		<label for="isq-invoice-justification-<?php echo (int) $invoice['orderId']; ?>"><?php esc_html_e( 'Reason for the cancellation (sent to SEFAZ)', 'infixs-squidcore-erp' ); ?></label>
		<textarea id="isq-invoice-justification-<?php echo (int) $invoice['orderId']; ?>" rows="3" maxlength="<?php echo (int) $max_length; ?>" data-isq-justification></textarea>
		<p class="isq-invoice__counter">
			<span data-isq-counter>0</span>/<?php echo (int) $max_length; ?>
			&middot;
			<?php
			/* translators: %d: minimum number of characters. */
			echo esc_html( sprintf( __( 'At least %d characters.', 'infixs-squidcore-erp' ), $min_length ) );
			?>
		</p>
		<div class="isq-invoice__cancel-actions">
			<button type="button" class="button button-primary isq-invoice__cancel-submit" data-isq-action="cancel" disabled><?php esc_html_e( 'Cancel invoice', 'infixs-squidcore-erp' ); ?></button>
			<button type="button" class="button" data-isq-action="cancel-close"><?php esc_html_e( 'Back', 'infixs-squidcore-erp' ); ?></button>
		</div>
	</div>
<?php endif; ?>
