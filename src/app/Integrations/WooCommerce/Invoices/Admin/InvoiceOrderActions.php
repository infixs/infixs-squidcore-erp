<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin;

use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosAutomatico;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeSnapshotService;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Support\OrderScreen;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk action "Print simplified DANFE (Correios)" of the order list (with and without HPOS): opens the print of
 * Correios Automático with only the simplified DANFE of the selected orders whose invoice gets one.
 *
 * WordPress (posts storage) and WooCommerce (HPOS) check the nonce of the list before running a bulk action; the
 * capability of the print page is checked here.
 *
 * @package Infixs\SquidCore
 * @since   1.4.0
 */
class InvoiceOrderActions {

	public const PRINT_DANFE_ACTION = 'infixs_squidcore_print_danfe';

	/**
	 * Query argument of the order list after a bulk print without any eligible order; its value is how many orders
	 * were selected.
	 */
	public const NO_DANFE_QUERY_VAR = 'infixs_squidcore_no_danfe';

	/**
	 * Screen id of the order list on the posts storage.
	 */
	private const LEGACY_LIST_SCREEN_ID = 'edit-shop_order';

	/**
	 * @var InvoiceService
	 */
	protected $service;

	/**
	 * @var DanfeSnapshotService
	 */
	protected $danfe;

	/**
	 * @var CorreiosAutomatico
	 */
	protected $correios;

	public function __construct( InvoiceService $service, DanfeSnapshotService $danfe, CorreiosAutomatico $correios ) {
		$this->service = $service;
		$this->danfe = $danfe;
		$this->correios = $correios;
	}

	public function register(): void {
		foreach ( array_merge( OrderScreen::HPOS_SCREEN_IDS, [ self::LEGACY_LIST_SCREEN_ID ] ) as $screen_id ) {
			add_filter( 'bulk_actions-' . $screen_id, [ $this, 'addActions' ] );
			add_filter( 'handle_bulk_actions-' . $screen_id, [ $this, 'handle' ], 10, 3 );
		}

		add_action( 'admin_notices', [ $this, 'renderNotice' ] );
	}

	/**
	 * @param array $actions Bulk actions of the order list.
	 *
	 * @return array
	 */
	public function addActions( $actions ) {
		$actions = is_array( $actions ) ? $actions : [];

		if ( $this->correios->canPrintDanfe() ) {
			$actions[ self::PRINT_DANFE_ACTION ] = __( 'Print simplified DANFE (Correios)', 'infixs-squidcore-erp' );
		}

		return $actions;
	}

	/**
	 * Sends to the print of the orders whose invoice gets a simplified DANFE, or back to the list with a notice when
	 * none does.
	 *
	 * @param string $redirect Address WordPress goes to after the action.
	 * @param string $action   Bulk action.
	 * @param int[]  $ids      Selected order ids.
	 *
	 * @return string
	 */
	public function handle( $redirect, $action, $ids ) {
		if ( $action !== self::PRINT_DANFE_ACTION || ! $this->correios->canPrintDanfe() ) {
			return $redirect;
		}

		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : [] ) ) );
		$url = $this->correios->danfePrintUrl( $this->eligible( $ids ) );

		if ( $url !== '' ) {
			return $url;
		}

		return add_query_arg( self::NO_DANFE_QUERY_VAR, count( $ids ), remove_query_arg( self::NO_DANFE_QUERY_VAR, (string) $redirect ) );
	}

	/**
	 * Tells, back on the order list, that no selected order has a simplified DANFE to print.
	 */
	public function renderNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads how many orders a past action selected.
		$selected = isset( $_GET[ self::NO_DANFE_QUERY_VAR ] ) ? absint( wp_unslash( $_GET[ self::NO_DANFE_QUERY_VAR ] ) ) : 0;

		if ( $selected === 0 || ! OrderScreen::isOrderListScreen() || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html__( 'None of the selected orders has an authorized NF-e to print the simplified DANFE.', 'infixs-squidcore-erp' )
		);
	}

	/**
	 * Orders whose invoice gets a simplified DANFE, in the order they were selected.
	 *
	 * @param int[] $ids
	 *
	 * @return int[]
	 */
	private function eligible( array $ids ): array {
		return array_values( array_filter( $ids, function ( $id ) {
			$order = wc_get_order( $id );
			$record = $order instanceof \WC_Order ? $this->service->find( $order ) : null;

			return $record !== null && $this->danfe->isEligible( $record );
		} ) );
	}
}
