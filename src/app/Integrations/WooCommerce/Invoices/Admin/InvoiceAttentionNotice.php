<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Support\OrderScreen;

defined( 'ABSPATH' ) || exit;

/**
 * "N invoices need your attention" on the order screens and the SquidCore pages. It still shows when another plugin
 * replaces the order list, since the notices of the admin header are kept.
 *
 * Counts invoices waiting for the store owner (pending items, rejected, denied, failed) and authorized invoices of
 * cancelled or refunded orders. The count is cached for a few minutes and forgotten whenever an invoice changes.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceAttentionNotice {

	public const TRANSIENT = 'infixs_squidcore_invoice_attention_count';

	private const CACHE_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * @var InvoiceRepository
	 */
	protected $repository;

	public function __construct( InvoiceRepository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'infixs_squidcore_invoice_state_changed', [ $this, 'forgetCount' ] );
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) || ! $this->isRelevantScreen() ) {
			return;
		}

		$count = $this->count();

		if ( $count < 1 ) {
			return;
		}

		$message = sprintf(
			/* translators: %d: number of invoices. */
			_n( '%d invoice (NF-e) needs your attention.', '%d invoices (NF-e) need your attention.', $count, 'infixs-squidcore-erp' ),
			$count
		);

		// Only administrators open the SquidCore pages; the others are told where to look.
		$next_step = current_user_can( 'manage_options' )
			? sprintf( ' <a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=infixs-squidcore&path=/invoices' ) ), esc_html__( 'See the invoices', 'infixs-squidcore-erp' ) )
			: ' ' . esc_html__( 'Open the orders flagged in the NF-e column of the orders list to see what is missing.', 'infixs-squidcore-erp' );

		printf(
			'<div class="notice notice-warning infixs-squidcore-invoice-notice"><p>%s%s</p></div>',
			esc_html( $message ),
			$next_step // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}

	public function count(): int {
		$cached = get_transient( self::TRANSIENT );

		if ( $cached !== false ) {
			return (int) $cached;
		}

		$count = $this->repository->countPending( InvoiceService::STATES_NEEDING_ACTION );
		set_transient( self::TRANSIENT, $count, self::CACHE_SECONDS );

		return $count;
	}

	public function forgetCount(): void {
		delete_transient( self::TRANSIENT );
	}

	private function isRelevantScreen(): bool {
		if ( OrderScreen::isOrderListScreen() || OrderScreen::isOrderEditScreen() ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which page is open.
		return isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === 'infixs-squidcore';
	}
}
