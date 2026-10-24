<?php

namespace Infixs\SquidCore\Integrations\CorreiosAutomatico;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeSnapshot;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeSnapshotService;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceApi;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceLabels;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRecord;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceSettings;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceUrls;
use Infixs\SquidCore\Services\ConnectionService;

defined( 'ABSPATH' ) || exit;

/**
 * Hands the invoice (NF-e) issued by SquidCore to Correios Automático, so the pre-posting goes out with the NF-e
 * instead of a content declaration (DC-e).
 *
 * Talks to Correios Automático only through its public hooks and order metas, so it is harmless when that plugin is
 * missing or older: the two metas are also read by the JadLog and Loggi plugins and by any Correios version.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class CorreiosInvoiceBridge {

	public const SOURCE = 'squidcore';

	public const PLUGIN_SLUG = 'infixs-squidcore';

	/**
	 * Order metas of the invoice read by Correios Automático, JadLog and Loggi.
	 */
	public const NUMBER_META = '_infixs_correios_automatico_invoice_number';

	public const KEY_META = '_infixs_correios_automatico_invoice_key';

	/**
	 * Access key of the NF-e the last Correios pre-posting of the order was created with.
	 */
	public const PREPOST_KEY_META = '_infixs_squidcore_correios_prepost_key';

	/**
	 * Version of the simplified DANFE block of `infixs_correios_automatico_order_danfe`
	 * (OrderDanfeResolver::SCHEMA of Correios Automático).
	 */
	public const DANFE_SCHEMA = 1;

	/**
	 * Panel state and tone of each invoice state (filter `infixs_correios_automatico_order_invoice_status`).
	 */
	private const PANEL_STATES = [
		InvoiceRecord::SCHEDULED => [ 'pending', 'warning' ],
		InvoiceRecord::EMITTING => [ 'pending', 'warning' ],
		InvoiceRecord::PROCESSING => [ 'pending', 'warning' ],
		InvoiceRecord::AUTHORIZED => [ 'authorized', 'success' ],
		InvoiceRecord::BLOCKED => [ 'error', 'danger' ],
		InvoiceRecord::ERROR => [ 'error', 'danger' ],
		InvoiceRecord::DENIED => [ 'error', 'danger' ],
		InvoiceRecord::FAILED => [ 'error', 'danger' ],
		InvoiceRecord::CANCELLED => [ 'cancelled', 'muted' ],
		InvoiceRecord::SKIPPED => [ 'none', 'muted' ],
	];

	/**
	 * @var InvoiceService
	 */
	protected $service;

	/**
	 * @var InvoiceSettings
	 */
	protected $settings;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	/**
	 * @var DanfeSnapshotService
	 */
	protected $danfe;

	public function __construct( InvoiceService $service, InvoiceSettings $settings, ConnectionService $connection, DanfeSnapshotService $danfe ) {
		$this->service = $service;
		$this->settings = $settings;
		$this->connection = $connection;
		$this->danfe = $danfe;
	}

	public function register(): void {
		add_filter( 'infixs_correios_automatico_order_invoice', [ $this, 'orderInvoice' ], 10, 3 );
		add_filter( 'infixs_correios_automatico_auto_prepost_wait_invoice', [ $this, 'waitInvoice' ], 10, 2 );
		add_filter( 'infixs_correios_automatico_active_plugins', [ $this, 'activePlugins' ] );
		add_filter( 'infixs_correios_automatico_order_danfe', [ $this, 'orderDanfe' ], 10, 3 );
		add_filter( 'infixs_correios_automatico_order_invoice_status', [ $this, 'invoiceStatus' ], 10, 3 );

		add_action( 'infixs_squidcore_invoice_authorized', [ $this, 'onAuthorized' ], 10, 3 );
		add_action( 'infixs_squidcore_invoice_failed', [ $this, 'onFailed' ], 10, 3 );
		add_action( 'infixs_squidcore_invoice_cancelled', [ $this, 'onCancelled' ], 10, 3 );
		add_action( 'infixs_correios_automatico_prepost_created', [ $this, 'onPrepostCreated' ], 10, 2 );
	}

	/**
	 * The authorized invoice of the order, unless another provider answered or a marketplace vendor posts the
	 * shipment (vendors issue their own invoices). A test (homologation) invoice has no fiscal value, so it never
	 * goes to the Correios of a live store.
	 *
	 * @param array|null $invoice Answer of the previous providers.
	 * @param \WC_Order  $order
	 * @param array      $context for, shipping_item_id, vendor_id.
	 *
	 * @return array|null
	 */
	public function orderInvoice( $invoice, $order, $context = [] ) {
		if ( $invoice !== null || ! $order instanceof \WC_Order ) {
			return $invoice;
		}

		if ( is_array( $context ) && ! empty( $context['vendor_id'] ) ) {
			return $invoice;
		}

		$record = $this->service->find( $order );

		if ( ! $record || ! $record->is( InvoiceRecord::AUTHORIZED ) || $record->key() === '' || ! $this->service->isUsable( $record ) ) {
			return $invoice;
		}

		return [
			'invoice_number' => $record->number(),
			'invoice_key' => $record->key(),
			'source' => self::SOURCE,
			'source_label' => 'SquidCore',
		];
	}

	/**
	 * Simplified DANFE (DANFE Simplificado - Etiqueta) of the authorized invoice, with raw values: Correios Automático
	 * validates and formats it. Answers unless another provider answered, a marketplace vendor posts the shipment or
	 * the order holds another NF-e; `pending` while the snapshot is being read, and the input (null) when it cannot be.
	 *
	 * @param array|null $danfe   Answer of the previous providers.
	 * @param \WC_Order  $order
	 * @param array      $context for (label or display), shipping_item_id, vendor_id, invoice (the NF-e Correios
	 *                            Automático resolved for the same shipment).
	 *
	 * @return array|null
	 */
	public function orderDanfe( $danfe, $order, $context = [] ) {
		if ( $danfe !== null || ! $order instanceof \WC_Order ) {
			return $danfe;
		}

		$context = is_array( $context ) ? $context : [];

		if ( ! empty( $context['vendor_id'] ) ) {
			return $danfe;
		}

		$record = $this->service->find( $order );

		if ( ! $record || ! $this->danfe->isEligible( $record ) || ! $this->holdsInvoice( $record, $context['invoice'] ?? null ) ) {
			return $danfe;
		}

		$snapshot = $this->danfe->ensure( $order, $record, ( $context['for'] ?? 'label' ) !== 'display' );

		switch ( $this->danfe->readinessOf( $snapshot ) ) {
			case DanfeSnapshotService::READY:
				return $this->danfeBlock( $snapshot );
			case DanfeSnapshotService::PENDING:
				return [
					'schema' => self::DANFE_SCHEMA,
					'pending' => true,
				];
		}

		return $danfe;
	}

	/**
	 * State of the invoice for the orders panel of Correios Automático: SquidCore's own state, its DANFE readiness and
	 * the download links the user may open. Leaves the default of Correios Automático when the order has no invoice
	 * of SquidCore, for marketplace vendors and when the order holds the NF-e of another provider.
	 *
	 * @param array|mixed $status  Default of Correios Automático (or of the previous providers).
	 * @param \WC_Order   $order
	 * @param array       $context vendor_id, invoice (the NF-e Correios Automático resolved), waiting.
	 *
	 * @return array|mixed
	 */
	public function invoiceStatus( $status, $order, $context = [] ) {
		if ( ! $order instanceof \WC_Order ) {
			return $status;
		}

		$context = is_array( $context ) ? $context : [];
		$record = empty( $context['vendor_id'] ) ? $this->service->find( $order ) : null;

		if ( ! $record || ! isset( self::PANEL_STATES[ $record->state() ] ) || ! $this->holdsInvoice( $record, $context['invoice'] ?? null ) ) {
			return $status;
		}

		[ $state, $tone ] = self::PANEL_STATES[ $record->state() ];
		$usable = $this->service->isUsable( $record );
		$detail = $record->message();

		if ( $record->is( InvoiceRecord::AUTHORIZED ) && ! $usable ) {
			$tone = 'muted';
			$detail = __( 'Test environment', 'infixs-squidcore-erp' );
		} elseif ( $record->is( InvoiceRecord::CANCELLED ) ) {
			$detail = InvoiceLabels::state( InvoiceRecord::CANCELLED );
		}

		if ( $record->attention() !== '' ) {
			$tone = 'danger';
			$detail = InvoiceLabels::attention( $record->attention() );
		}

		return [
			'state' => $state,
			'tone' => $tone,
			'label' => $this->panelLabel( $record ),
			'detail' => $detail,
			'source' => self::SOURCE,
			'source_label' => 'SquidCore',
			'danfe' => $this->danfe->readiness( $order, $record ),
			'actions' => $this->panelActions( $order, $record ),
		];
	}

	/**
	 * Whether the automatic pre-posting of Correios Automático PRO waits for the invoice of the order. It does not
	 * wait for a test (homologation) invoice, which would never be handed over.
	 *
	 * @param bool      $wait Answer of the previous providers.
	 * @param \WC_Order $order
	 */
	public function waitInvoice( $wait, $order ): bool {
		if ( $wait || ! $order instanceof \WC_Order ) {
			return (bool) $wait;
		}

		if ( ! $this->settings->correiosWait() || ! $this->connection->isConnected() ) {
			return false;
		}

		$record = $this->service->find( $order );

		if ( $record && $record->environment() !== '' && ! $this->service->isUsable( $record ) ) {
			return false;
		}

		return $this->service->expectsInvoice( $order );
	}

	/**
	 * Lists SquidCore in the Extensions page of Correios Automático.
	 *
	 * @param mixed $plugins Slugs of the active plugins of the family.
	 *
	 * @return mixed
	 */
	public function activePlugins( $plugins ) {
		if ( ! is_array( $plugins ) || in_array( self::PLUGIN_SLUG, $plugins, true ) ) {
			return $plugins;
		}

		$plugins[] = self::PLUGIN_SLUG;

		return $plugins;
	}

	/**
	 * Writes the invoice in the order metas the carriers read, unless the store already filled them or it is a test
	 * (homologation) invoice, then tells Correios Automático the invoice is ready, so a pre-posting waiting for it
	 * goes out (with a content declaration when the invoice was not handed over).
	 *
	 * @param int            $orderId
	 * @param array          $invoice Invoice record.
	 * @param \WC_Order|null $order   Order the record was saved on.
	 */
	public function onAuthorized( $orderId, $invoice, $order = null ): void {
		$order = $this->order( $orderId, $order );
		$key = $this->digits( $invoice['key'] ?? '' );
		$record = $order ? $this->service->find( $order ) : null;

		if ( $record && $this->service->isUsable( $record ) && $key !== '' && $this->digits( $order->get_meta( self::KEY_META, true ) ) === '' && trim( (string) $order->get_meta( self::NUMBER_META, true ) ) === '' ) {
			$order->update_meta_data( self::NUMBER_META, sanitize_text_field( (string) ( $invoice['number'] ?? '' ) ) );
			$order->update_meta_data( self::KEY_META, $key );
			$order->save_meta_data();
		}

		do_action( 'infixs_correios_automatico_order_invoice_ready', (int) $orderId );
	}

	/**
	 * @param int    $orderId
	 * @param string $state   blocked, error, denied or failed.
	 * @param string $message Message for the store owner.
	 */
	public function onFailed( $orderId, $state, $message = '' ): void {
		do_action( 'infixs_correios_automatico_order_invoice_failed', (int) $orderId, (string) $message );
	}

	/**
	 * Takes the cancelled invoice out of the carrier metas and warns the store owner when Correios already
	 * pre-posted with it.
	 *
	 * @param int            $orderId
	 * @param array          $invoice Invoice record.
	 * @param \WC_Order|null $order   Order the record was saved on.
	 */
	public function onCancelled( $orderId, $invoice, $order = null ): void {
		$order = $this->order( $orderId, $order );
		$key = $this->digits( $invoice['key'] ?? '' );

		if ( ! $order || $key === '' ) {
			return;
		}

		if ( $this->digits( $order->get_meta( self::KEY_META, true ) ) === $key ) {
			$order->delete_meta_data( self::NUMBER_META );
			$order->delete_meta_data( self::KEY_META );
			$order->save_meta_data();
		}

		$record = $this->service->find( $order );

		if ( $record && $order->get_meta( self::PREPOST_KEY_META, true ) === $key ) {
			$this->service->flagAttention( $order, $record, InvoiceRecord::ATTENTION_PREPOST_USES_CANCELLED_INVOICE );
		}
	}

	/**
	 * Remembers the pre-postings made with the invoice of SquidCore. A new pre-posting after the invoice was
	 * cancelled means the store owner redid it, so the warning goes away.
	 *
	 * @param int    $orderId
	 * @param object $prepost Pre-posting saved by Correios Automático.
	 */
	public function onPrepostCreated( $orderId, $prepost = null ): void {
		// A return (reverse logistics) brings the parcel back; it says nothing about the invoice of the shipment.
		if ( is_object( $prepost ) && isset( $prepost->reverse_logistic ) && $prepost->reverse_logistic === 'S' ) {
			return;
		}

		$order = $this->order( $orderId, null );
		$record = $order ? $this->service->find( $order ) : null;

		if ( ! $record ) {
			return;
		}

		$key = $this->prepostKey( $order, $prepost );

		if ( $record->attention() === InvoiceRecord::ATTENTION_PREPOST_USES_CANCELLED_INVOICE && $key !== $order->get_meta( self::PREPOST_KEY_META, true ) ) {
			$this->service->clearAttention( $order, $record );
		}

		if ( $key !== '' && $record->is( InvoiceRecord::AUTHORIZED ) && $key === $record->key() ) {
			$order->update_meta_data( self::PREPOST_KEY_META, $key );
			$order->save_meta_data();
		}
	}

	/**
	 * The DANFE block of a ready snapshot (contract of `infixs_correios_automatico_order_danfe`, schema 1).
	 */
	private function danfeBlock( DanfeSnapshot $snapshot ): array {
		return [
			'schema' => self::DANFE_SCHEMA,
			'source' => self::SOURCE,
			'source_label' => 'SquidCore',
			'environment' => $snapshot->environment(),
			'key' => $snapshot->key(),
			'number' => $snapshot->number(),
			'series' => $snapshot->series(),
			'operation' => $snapshot->operation(),
			'issued_at' => $snapshot->issuedAt(),
			'protocol' => [
				'number' => $snapshot->protocol(),
				'authorized_at' => $snapshot->authorizedAt(),
			],
			'emitter' => $this->danfeParty( $snapshot->emitter() ),
			'recipient' => $this->danfeParty( $snapshot->recipient() ),
			'total' => $snapshot->total(),
			'contingency' => null,
		];
	}

	/**
	 * @param array $party Issuer or recipient of the snapshot.
	 *
	 * @return array{name: string, document: string, ie: string, uf: string}
	 */
	private function danfeParty( array $party ): array {
		return [
			'name' => (string) $party['name'],
			'document' => (string) $party['document'],
			'ie' => (string) $party['ie'],
			'uf' => (string) $party['uf'],
		];
	}

	/**
	 * Whether the NF-e Correios Automático resolved for the order (when it says) is the invoice of SquidCore.
	 *
	 * @param mixed $invoice Resolved NF-e: invoice_key, source...
	 */
	private function holdsInvoice( InvoiceRecord $record, $invoice ): bool {
		$key = is_array( $invoice ) ? $this->digits( $invoice['invoice_key'] ?? '' ) : '';

		return $key === '' || $key === $record->key();
	}

	private function panelLabel( InvoiceRecord $record ): string {
		if ( $record->is( InvoiceRecord::AUTHORIZED, InvoiceRecord::CANCELLED ) && $record->number() !== '' ) {
			/* translators: %s: invoice number. */
			return sprintf( __( 'NF-e no. %s', 'infixs-squidcore-erp' ), $record->number() );
		}

		/* translators: %s: state of the invoice, e.g. "Processing". */
		return sprintf( __( 'NF-e: %s', 'infixs-squidcore-erp' ), InvoiceLabels::state( $record->state() ) );
	}

	/**
	 * Download links of an authorized or cancelled invoice, for users who may download invoices.
	 *
	 * @return array[] {id, label, url, target?}
	 */
	private function panelActions( \WC_Order $order, InvoiceRecord $record ): array {
		if ( ! $record->is( InvoiceRecord::AUTHORIZED, InvoiceRecord::CANCELLED ) || $record->key() === '' || ! current_user_can( 'edit_shop_orders' ) ) {
			return [];
		}

		$actions = [
			[
				'id' => 'danfe_pdf',
				'label' => __( 'Full DANFE (PDF)', 'infixs-squidcore-erp' ),
				'url' => InvoiceUrls::adminDownload( $order->get_id(), InvoiceApi::FILE_DANFE ),
				'target' => '_blank',
			],
			[
				'id' => 'xml',
				'label' => __( 'XML', 'infixs-squidcore-erp' ),
				'url' => InvoiceUrls::adminDownload( $order->get_id(), InvoiceApi::FILE_XML ),
			],
		];

		if ( $record->is( InvoiceRecord::CANCELLED ) ) {
			$actions[] = [
				'id' => 'cancellation_xml',
				'label' => __( 'Cancellation XML', 'infixs-squidcore-erp' ),
				'url' => InvoiceUrls::adminDownload( $order->get_id(), InvoiceApi::FILE_CANCELLATION_XML ),
			];
		}

		return $actions;
	}

	/**
	 * Key of the invoice a pre-posting was created with: blank for a content declaration (DC-e), otherwise the one
	 * Correios returned or, when it returned none, the one Correios Automático wrote in the order.
	 *
	 * @param object|null $prepost
	 */
	private function prepostKey( \WC_Order $order, $prepost ): string {
		if ( is_object( $prepost ) && ! empty( $prepost->dce ) ) {
			return '';
		}

		$key = is_object( $prepost ) && isset( $prepost->invoice_key ) ? $this->digits( $prepost->invoice_key ) : '';

		return $key !== '' ? $key : $this->digits( $order->get_meta( self::KEY_META, true ) );
	}

	/**
	 * @param int|string     $orderId
	 * @param \WC_Order|null $order
	 */
	private function order( $orderId, $order ): ?\WC_Order {
		if ( $order instanceof \WC_Order && $order->get_id() === (int) $orderId ) {
			return $order;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $orderId ) : null;

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * @param mixed $value
	 */
	private function digits( $value ): string {
		return is_scalar( $value ) ? (string) preg_replace( '/\D+/', '', (string) $value ) : '';
	}
}
