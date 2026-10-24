<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin;

use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosAutomatico;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeSnapshotService;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceApi;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceLabels;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRecord;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceUrls;
use Infixs\SquidCore\Support\OrderScreen;

defined( 'ABSPATH' ) || exit;

/**
 * "NF-e" column of the order list (with and without HPOS), with links to the full DANFE and, when Correios Automático
 * prints it, to the simplified DANFE. Reads the order metas only, never the API.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceOrderColumn {

	public const COLUMN = 'infixs_squidcore_invoice';

	/**
	 * WooCommerce status colors used for each invoice state.
	 */
	private const STATUS_CLASSES = [
		InvoiceRecord::AUTHORIZED => 'status-processing',
		InvoiceRecord::SCHEDULED => 'status-on-hold',
		InvoiceRecord::EMITTING => 'status-on-hold',
		InvoiceRecord::PROCESSING => 'status-on-hold',
		InvoiceRecord::BLOCKED => 'status-failed',
		InvoiceRecord::ERROR => 'status-failed',
		InvoiceRecord::DENIED => 'status-failed',
		InvoiceRecord::FAILED => 'status-failed',
	];

	/**
	 * @var InvoiceRepository
	 */
	protected $repository;

	/**
	 * @var DanfeSnapshotService
	 */
	protected $danfe;

	/**
	 * @var CorreiosAutomatico
	 */
	protected $correios;

	public function __construct( InvoiceRepository $repository, DanfeSnapshotService $danfe, CorreiosAutomatico $correios ) {
		$this->repository = $repository;
		$this->danfe = $danfe;
		$this->correios = $correios;
	}

	public function register(): void {
		foreach ( OrderScreen::HPOS_SCREEN_IDS as $screen_id ) {
			add_filter( 'manage_' . $screen_id . '_columns', [ $this, 'addColumn' ] );
			add_action( 'manage_' . $screen_id . '_custom_column', [ $this, 'renderOrder' ], 10, 2 );
		}

		// Posts storage.
		add_filter( 'manage_edit-shop_order_columns', [ $this, 'addColumn' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ $this, 'renderPost' ], 10, 2 );
	}

	/**
	 * Adds the column after the order status.
	 *
	 * @param array $columns
	 *
	 * @return array
	 */
	public function addColumn( $columns ) {
		$columns = is_array( $columns ) ? $columns : [];
		$result = [];

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;

			if ( $key === 'order_status' ) {
				$result[ self::COLUMN ] = __( 'NF-e', 'infixs-squidcore-erp' );
			}
		}

		if ( ! isset( $result[ self::COLUMN ] ) ) {
			$result[ self::COLUMN ] = __( 'NF-e', 'infixs-squidcore-erp' );
		}

		return $result;
	}

	/**
	 * @param string          $column
	 * @param \WC_Order|mixed $order
	 */
	public function renderOrder( $column, $order ): void {
		if ( $column !== self::COLUMN || ! $order instanceof \WC_Order ) {
			return;
		}

		echo $this->cell( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cell().
	}

	/**
	 * @param string $column
	 * @param int    $postId
	 */
	public function renderPost( $column, $postId ): void {
		if ( $column !== self::COLUMN ) {
			return;
		}

		$this->renderOrder( $column, wc_get_order( (int) $postId ) );
	}

	public function cell( \WC_Order $order ): string {
		$record = $this->repository->find( $order );

		if ( ! $record ) {
			return '<span aria-hidden="true">&ndash;</span><span class="screen-reader-text">' . esc_html( InvoiceLabels::state( '' ) ) . '</span>';
		}

		$label = InvoiceLabels::state( $record->state() );
		$title = $record->message() !== '' ? $record->message() : $label;
		$class = self::STATUS_CLASSES[ $record->state() ] ?? 'status-cancelled';

		if ( $record->is( InvoiceRecord::AUTHORIZED, InvoiceRecord::CANCELLED ) && $record->number() !== '' ) {
			/* translators: %s: invoice number. */
			$text = sprintf( __( 'No. %s', 'infixs-squidcore-erp' ), $record->number() );
			$title = $label;
		} else {
			$text = $label;
		}

		if ( $record->attention() !== '' ) {
			$class = 'status-failed';
			$title = InvoiceLabels::attention( $record->attention() );
			$text = '<span class="dashicons dashicons-warning" aria-hidden="true" style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span> ' . esc_html( $text );
		} else {
			$text = esc_html( $text );
		}

		return sprintf(
			'<mark class="order-status %s" title="%s"><span>%s</span></mark>%s',
			esc_attr( $class ),
			esc_attr( $title ),
			$text,
			$this->links( $order, $record )
		);
	}

	/**
	 * "DANFE" (the full PDF, for authorized and cancelled invoices) and "Label" (the simplified DANFE printed by
	 * Correios Automático, for invoices that get one), each for the users who may open it.
	 */
	private function links( \WC_Order $order, InvoiceRecord $record ): string {
		$links = [];

		if ( $record->is( InvoiceRecord::AUTHORIZED, InvoiceRecord::CANCELLED ) && $record->key() !== '' && current_user_can( 'edit_shop_orders' ) ) {
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a>',
				esc_url( InvoiceUrls::adminDownload( $order->get_id(), InvoiceApi::FILE_DANFE ) ),
				esc_attr__( 'Download the full DANFE (PDF)', 'infixs-squidcore-erp' ),
				esc_html__( 'DANFE', 'infixs-squidcore-erp' )
			);
		}

		$print_url = $this->danfe->isEligible( $record ) ? $this->correios->danfePrintUrl( [ $order->get_id() ] ) : '';

		if ( $print_url !== '' ) {
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a>',
				esc_url( $print_url ),
				esc_attr__( 'Print the simplified DANFE with Correios Automático', 'infixs-squidcore-erp' ),
				esc_html_x( 'Label', 'link to print the simplified DANFE', 'infixs-squidcore-erp' )
			);
		}

		return $links ? '<div class="isq-invoice-column__links" style="margin-top:4px;">' . implode( ' | ', $links ) . '</div>' : '';
	}
}
