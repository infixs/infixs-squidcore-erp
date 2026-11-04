<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRecord;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit\PlanLimitLabels;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit\PlanLimitRetry;
use Infixs\SquidCore\Support\OrderScreen;
use Infixs\SquidCore\Support\SquidCoreUrls;

defined( 'ABSPATH' ) || exit;

/**
 * "N invoices need your attention" on the order screens and the SquidCore pages. It still shows when another plugin
 * replaces the order list, since the notices of the admin header are kept.
 *
 * Counts invoices waiting for the store owner (pending items, rejected, denied, failed) and authorized invoices of
 * cancelled or refunded orders. The ones the invoice limit of the plan refused get a notice of their own, once for
 * the whole store, with the plans of SquidCore and the button that issues them all again, on the order screens only:
 * inside the SquidCore app the Dashboard and the Invoices screens show it themselves. The other notice counts the
 * rest. The counts are cached for a few minutes and forgotten whenever an invoice changes.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceAttentionNotice {

	public const TRANSIENT = 'infixs_squidcore_invoice_attention_count';

	public const PLAN_LIMIT_TRANSIENT = 'infixs_squidcore_invoice_plan_limit_count';

	/**
	 * admin-post action and nonce action of "Issue the pending invoices".
	 */
	public const RETRY_ACTION = 'infixs_squidcore_plan_limit_retry';

	private const CACHE_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * @var InvoiceRepository
	 */
	protected $repository;

	/**
	 * @var PlanLimitRetry
	 */
	protected $retry;

	/**
	 * @var SquidCoreUrls
	 */
	protected $urls;

	public function __construct( InvoiceRepository $repository, PlanLimitRetry $retry, SquidCoreUrls $urls ) {
		$this->repository = $repository;
		$this->retry = $retry;
		$this->urls = $urls;
	}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'admin_post_' . self::RETRY_ACTION, [ $this, 'handleRetry' ] );
		add_action( 'infixs_squidcore_invoice_state_changed', [ $this, 'forgetCount' ] );
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) || ! $this->isRelevantScreen() ) {
			return;
		}

		$plan_limit = $this->planLimitCount();

		if ( $plan_limit > 0 && ! $this->isAppPage() ) {
			echo $this->planLimitHtml( $plan_limit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in planLimitHtml().
		}

		$count = max( 0, $this->count() - $plan_limit );

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

	/**
	 * "Issue the pending invoices": queues the re-emission and goes back to the page, where the notice tells how far it
	 * went. A store that is not connected is told why nothing was queued; with nothing left to issue (issued meanwhile)
	 * the page simply comes back without the notice.
	 */
	public function handleRetry(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'infixs-squidcore-erp' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::RETRY_ACTION );

		$started = $this->retry->start();
		$this->forgetCount();

		if ( is_wp_error( $started ) && $started->get_error_code() !== PlanLimitRetry::NOTHING_TO_ISSUE ) {
			wp_die( esc_html( $started->get_error_message() ), '', [
				'response' => 409,
				'back_link' => true,
			] );
		}

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'edit.php?post_type=shop_order' ) );
		exit;
	}

	/**
	 * Orders whose invoice waits for the store owner, the ones refused by the plan limit included.
	 */
	public function count(): int {
		return $this->cached( self::TRANSIENT, function (): int {
			return $this->repository->countPending( InvoiceService::STATES_NEEDING_ACTION );
		} );
	}

	/**
	 * Orders whose invoice the invoice limit of the plan refused.
	 */
	public function planLimitCount(): int {
		return $this->cached( self::PLAN_LIMIT_TRANSIENT, function (): int {
			return $this->repository->countByFailure( InvoiceRecord::FAILURE_PLAN_LIMIT );
		} );
	}

	public function forgetCount(): void {
		delete_transient( self::TRANSIENT );
		delete_transient( self::PLAN_LIMIT_TRANSIENT );
	}

	/**
	 * @param callable(): int $count
	 */
	private function cached( string $transient, callable $count ): int {
		$cached = get_transient( $transient );

		if ( $cached !== false ) {
			return (int) $cached;
		}

		$value = $count();
		set_transient( $transient, $value, self::CACHE_SECONDS );

		return $value;
	}

	/**
	 * One notice for every order the plan limit left without an invoice: the plans of SquidCore and, unless a
	 * re-emission is on its way, the button that issues them all again (a form, so it works without JavaScript).
	 */
	private function planLimitHtml( int $orders ): string {
		$retry = $this->retry->status();
		$running = $retry['status'] === PlanLimitRetry::RUNNING;
		$detail = PlanLimitLabels::retry( $retry );

		$buttons = sprintf(
			'<a class="button button-primary" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $this->urls->plansUrl() ),
			esc_html( PlanLimitLabels::seePlans() )
		);

		if ( ! $running ) {
			$buttons .= sprintf(
				' <button type="submit" class="button">%s</button>',
				esc_html( PlanLimitLabels::issuePending() )
			);
		}

		$actions = $running
			? sprintf( '<p>%s</p>', $buttons )
			: sprintf(
				'<form method="post" action="%s"><input type="hidden" name="action" value="%s">%s<p>%s</p></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( self::RETRY_ACTION ),
				wp_nonce_field( self::RETRY_ACTION, '_wpnonce', true, false ),
				$buttons
			);

		return sprintf(
			'<div class="notice notice-warning infixs-squidcore-invoice-notice" data-isq-plan-limit><p><strong>%s</strong></p><p>%s</p>%s</div>',
			esc_html( PlanLimitLabels::notice( $orders ) ),
			esc_html( $detail !== '' ? $detail : PlanLimitLabels::nextStep() ),
			$actions
		);
	}

	private function isRelevantScreen(): bool {
		return OrderScreen::isOrderListScreen() || OrderScreen::isOrderEditScreen() || $this->isAppPage();
	}

	private function isAppPage(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which page is open.
		return isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === 'infixs-squidcore';
	}
}
