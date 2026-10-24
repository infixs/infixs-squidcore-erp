<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin;

use Infixs\SquidCore\AvelPress\View\View;
use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosAutomatico;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeSnapshotService;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceLabels;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoicePresenter;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRecord;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Support\OrderScreen;
use Infixs\SquidCore\Support\SquidCoreUrls;

defined( 'ABSPATH' ) || exit;

/**
 * "Invoice (NF-e)" box on the order edit screen (with and without HPOS).
 *
 * The box is rendered on the server; its script calls the order invoice REST routes, which answer with the box
 * rendered again, so an action never reloads the page (unsaved order changes are kept).
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceMetaBox {

	public const ID = 'infixs-squidcore-invoice';

	private const ASSET_HANDLE = 'infixs-squidcore-order-invoice';

	/**
	 * Blocking reason of a product without NCM.
	 */
	private const NCM_REASON = 'product.fiscal.ncm';

	/**
	 * @var InvoiceService
	 */
	protected $service;

	/**
	 * @var InvoicePresenter
	 */
	protected $presenter;

	/**
	 * @var View
	 */
	protected $view;

	/**
	 * @var SquidCoreUrls
	 */
	protected $urls;

	/**
	 * @var DanfeSnapshotService
	 */
	protected $danfe;

	/**
	 * @var CorreiosAutomatico
	 */
	protected $correios;

	public function __construct( InvoiceService $service, InvoicePresenter $presenter, View $view, SquidCoreUrls $urls, DanfeSnapshotService $danfe, CorreiosAutomatico $correios ) {
		$this->service = $service;
		$this->presenter = $presenter;
		$this->view = $view;
		$this->urls = $urls;
		$this->danfe = $danfe;
		$this->correios = $correios;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * @param string $screenId Post type (posts storage) or screen id (HPOS).
	 * @param mixed  $object   WP_Post or WC_Order.
	 */
	public function add( $screenId, $object = null ): void {
		if ( $screenId !== OrderScreen::id() || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		add_meta_box( self::ID, __( 'Invoice (NF-e)', 'infixs-squidcore-erp' ), [ $this, 'render' ], OrderScreen::id(), 'side', 'default' );
	}

	/**
	 * @param \WP_Post|\WC_Order|mixed $object
	 */
	public function render( $object ): void {
		$order = $object instanceof \WC_Order ? $object : wc_get_order( isset( $object->ID ) ? (int) $object->ID : 0 );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		printf(
			'<div class="isq-invoice" data-isq-invoice data-order-id="%d" aria-live="polite">%s</div>',
			(int) $order->get_id(),
			$this->html( $order ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the template.
		);
	}

	/**
	 * Content of the box.
	 *
	 * @param array $notice Optional message on top: {type: success|error|info, message}.
	 */
	public function html( \WC_Order $order, array $notice = [] ): string {
		ob_start();
		$this->view->make( 'admin.invoice-meta-box', $this->viewData( $order, $notice ) );

		return (string) ob_get_clean();
	}

	public function enqueue(): void {
		if ( ! OrderScreen::isOrderEditScreen() || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$path = 'assets/admin/';

		wp_enqueue_style(
			self::ASSET_HANDLE,
			\INFIXS_SQUIDCORE_PLUGIN_URL . $path . 'css/order-invoice.css',
			[],
			(string) filemtime( \INFIXS_SQUIDCORE_PLUGIN_PATH . $path . 'css/order-invoice.css' )
		);

		wp_enqueue_script(
			self::ASSET_HANDLE,
			\INFIXS_SQUIDCORE_PLUGIN_URL . $path . 'js/order-invoice.js',
			[ 'wp-api-fetch' ],
			(string) filemtime( \INFIXS_SQUIDCORE_PLUGIN_PATH . $path . 'js/order-invoice.js' ),
			true
		);

		wp_localize_script( self::ASSET_HANDLE, 'infixsSquidCoreOrderInvoice', [
			'restPath' => '/infixs-squidcore/v1/orders/',
			'minLength' => InvoiceService::JUSTIFICATION_MIN_LENGTH,
			'maxLength' => InvoiceService::JUSTIFICATION_MAX_LENGTH,
			'i18n' => [
				'error' => __( 'The action could not be completed. Try again.', 'infixs-squidcore-erp' ),
			],
		] );
	}

	/**
	 * @return array
	 */
	private function viewData( \WC_Order $order, array $notice ): array {
		$record = $this->service->find( $order );
		$invoice = $this->presenter->present( $order, $record );
		$state = $record ? $record->state() : '';
		$ai_fillable = $record !== null && $record->hasAiFillableReasons();
		// The hint invites to connect an AI, so it only makes sense while the organization has none.
		$ncm_hint = ! $ai_fillable && ! ( $record !== null && $record->aiNcmAvailable() ) && $this->hasNcmReason( $invoice['reasons'] );
		$scheduled_at = $invoice['scheduledAt'] ? strtotime( $invoice['scheduledAt'] ) : 0;
		$deadline = $invoice['cancelDeadline'] ? strtotime( $invoice['cancelDeadline'] ) : 0;
		$authorized_at = $invoice['invoice']['authorizedAt'] ?? null;
		$authorized_at = $authorized_at ? strtotime( $authorized_at ) : 0;
		$danfe_eligible = $record !== null && $this->danfe->isEligible( $record ) && $this->correios->supportsDanfe();

		return [
			'invoice' => $invoice,
			'notice' => $notice,
			'is_new_order' => $order->get_status() === 'auto-draft',
			'emit_label' => $this->emitLabel( $state ),
			'emit_confirm' => in_array( $state, [ InvoiceRecord::DENIED, InvoiceRecord::CANCELLED ], true )
				? __( 'A new invoice will be issued for this order. Continue?', 'infixs-squidcore-erp' )
				: '',
			'scheduled_text' => $scheduled_at ? InvoiceLabels::dateTime( $scheduled_at ) : '',
			'deadline_text' => $deadline ? InvoiceLabels::dateTime( $deadline ) : '',
			'authorized_text' => $authorized_at ? InvoiceLabels::dateTime( $authorized_at ) : '',
			'key_text' => isset( $invoice['invoice']['key'] ) && $invoice['invoice']['key'] !== '' ? implode( ' ', str_split( $invoice['invoice']['key'], 4 ) ) : '',
			'reasons' => $invoice['reasons'],
			'ai_fillable' => $ai_fillable,
			'ncm_hint' => $ncm_hint,
			'ai_connect_url' => $ncm_hint ? $this->urls->appUrl() . '/integrations' : '',
			'min_length' => InvoiceService::JUSTIFICATION_MIN_LENGTH,
			'max_length' => InvoiceService::JUSTIFICATION_MAX_LENGTH,
			'danfe_print_url' => $danfe_eligible ? $this->correios->danfePrintUrl( [ $order->get_id() ] ) : '',
			'danfe_status_text' => $danfe_eligible ? $this->danfeStatusText( $this->danfe->readiness( $order, $record ) ) : '',
		];
	}

	/**
	 * Whether the simplified DANFE of the invoice is ready to print with the Correios labels.
	 *
	 * @param string $readiness DanfeSnapshotService::READY, PENDING or UNAVAILABLE.
	 */
	private function danfeStatusText( string $readiness ): string {
		switch ( $readiness ) {
			case DanfeSnapshotService::READY:
				return __( 'Simplified DANFE ready: it prints with the Correios labels.', 'infixs-squidcore-erp' );
			case DanfeSnapshotService::PENDING:
				return __( 'Simplified DANFE being prepared: it prints with the Correios labels once ready.', 'infixs-squidcore-erp' );
			case DanfeSnapshotService::UNAVAILABLE:
				return __( 'The simplified DANFE could not be read from the invoice XML. Print the full DANFE instead.', 'infixs-squidcore-erp' );
		}

		return '';
	}

	private function emitLabel( string $state ): string {
		if ( in_array( $state, [ InvoiceRecord::DENIED, InvoiceRecord::CANCELLED ], true ) ) {
			return __( 'Issue a new invoice', 'infixs-squidcore-erp' );
		}

		if ( in_array( $state, [ InvoiceRecord::BLOCKED, InvoiceRecord::ERROR, InvoiceRecord::FAILED, InvoiceRecord::EMITTING ], true ) ) {
			return __( 'Try again', 'infixs-squidcore-erp' );
		}

		return __( 'Issue now', 'infixs-squidcore-erp' );
	}

	/**
	 * @param array[] $reasons
	 */
	private function hasNcmReason( array $reasons ): bool {
		foreach ( $reasons as $reason ) {
			if ( ( $reason['code'] ?? '' ) === self::NCM_REASON ) {
				return true;
			}
		}

		return false;
	}
}
