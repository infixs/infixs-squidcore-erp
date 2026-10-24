<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe;

use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosAutomatico;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceApi;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRecord;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Services\ConnectionService;
use Infixs\SquidCore\Support\ApiErrorMessage;
use Infixs\SquidCore\Support\BackgroundQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and keeps the simplified DANFE snapshot of the authorized invoices, for Correios Automático to print it with
 * the labels.
 *
 * The XML is downloaded once per invoice: right when it is authorized (before the bridge to Correios Automático hands
 * the invoice over, so a pre-posting printed automatically already finds it), again when a pre-posting is created
 * without it, and at print time within a small budget per request. A failed read is retried in the background. The
 * orders panel only reads what is saved: it neither downloads nor queues anything.
 *
 * Only invoices that may stand for the order outside SquidCore (production, or homologation on local and development
 * sites) of a connected store get a snapshot, and only while Correios Automático can print it, so a store without it
 * never downloads an XML for this.
 *
 * @package Infixs\SquidCore
 * @since   1.4.0
 */
class DanfeSnapshotService {

	/**
	 * Readiness of the simplified DANFE of an order, as told to Correios Automático.
	 */
	public const READY = 'ready';

	public const PENDING = 'pending';

	/**
	 * The order has an invoice, but its DANFE data could not be read.
	 */
	public const UNAVAILABLE = 'unavailable';

	public const NONE = 'none';

	/**
	 * Reads at print time per PHP request (filter `infixs_squidcore_danfe_lazy_fetch_limit`) and the seconds they may
	 * take together; the other orders are read in the background meanwhile.
	 */
	public const LAZY_FETCH_LIMIT = 3;

	public const LAZY_FETCH_SECONDS = 8;

	/**
	 * Seconds a download may take while a request waits on it.
	 */
	private const INTERACTIVE_TIMEOUT = 8;

	/**
	 * Seconds a download of the backfill may take: a slow SquidCore must not hold one run (and its PHP worker) for
	 * minutes.
	 */
	private const BACKFILL_TIMEOUT = 15;

	/**
	 * Error codes of SquidCore for an XML it does not have (yet).
	 */
	private const UNAVAILABLE_CODES = [ 'NFE_FILE_NOT_AVAILABLE', 'NFE_NOT_FOUND', 'NFE_NOT_AUTHORIZED' ];

	/**
	 * @var InvoiceApi
	 */
	protected $api;

	/**
	 * @var InvoiceService
	 */
	protected $invoices;

	/**
	 * @var DanfeXmlParser
	 */
	protected $parser;

	/**
	 * @var DanfeSnapshotRepository
	 */
	protected $repository;

	/**
	 * @var CorreiosAutomatico
	 */
	protected $correios;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	/**
	 * Reads made at print time in this request.
	 *
	 * @var int
	 */
	private $lazyFetches = 0;

	/**
	 * When the first read at print time of this request started (microtime), null before it.
	 *
	 * @var float|null
	 */
	private $lazyStartedAt = null;

	public function __construct(
		InvoiceApi $api,
		InvoiceService $invoices,
		DanfeXmlParser $parser,
		DanfeSnapshotRepository $repository,
		CorreiosAutomatico $correios,
		ConnectionService $connection,
		BackgroundQueue $queue
	) {
		$this->api = $api;
		$this->invoices = $invoices;
		$this->parser = $parser;
		$this->repository = $repository;
		$this->correios = $correios;
		$this->connection = $connection;
		$this->queue = $queue;
	}

	public function register(): void {
		// Before the bridge to Correios Automático (priority 10), which may pre-post and queue the automatic print.
		add_action( 'infixs_squidcore_invoice_authorized', [ $this, 'onAuthorized' ], 5, 3 );
		add_action( 'infixs_squidcore_invoice_cancelled', [ $this, 'onCancelled' ], 10, 3 );
		// Before the automatic print of Correios Automático PRO (priority 20).
		add_action( 'infixs_correios_automatico_prepost_created', [ $this, 'onPrepostCreated' ], 5, 2 );
	}

	/**
	 * Whether the store may download XML files for the snapshots: Correios Automático prints them, the store is
	 * connected and PHP can read XML.
	 */
	public function canFetch(): bool {
		return $this->correios->supportsDanfe() && $this->connection->isConnected() && class_exists( \DOMDocument::class );
	}

	/**
	 * Whether the invoice gets a snapshot: authorized, with its key, and usable outside SquidCore.
	 */
	public function isEligible( InvoiceRecord $record ): bool {
		return $record->is( InvoiceRecord::AUTHORIZED ) && $record->key() !== '' && $this->invoices->isUsable( $record );
	}

	/**
	 * Snapshot of the invoice the order holds now (ready or failed); null when missing or stale.
	 */
	public function current( \WC_Order $order, InvoiceRecord $record ): ?DanfeSnapshot {
		$snapshot = $this->repository->find( $order );

		return $snapshot && $snapshot->isFreshFor( $record ) ? $snapshot : null;
	}

	/**
	 * Snapshot for a print. A missing one (or a failed one whose retry is due) is read now while the budget of the
	 * request lasts, otherwise it is queued and the print goes on without it.
	 *
	 * A display request (the orders panel lists every order, of any age or status) only reads what is saved: queueing
	 * from it would download the XML of old and shipped orders the backfill leaves out on purpose.
	 *
	 * @param bool $mayFetch Whether this is a print, which may download the XML or queue its read.
	 */
	public function ensure( \WC_Order $order, InvoiceRecord $record, bool $mayFetch ): ?DanfeSnapshot {
		$snapshot = $this->current( $order, $record );

		if ( ! $mayFetch || ( $snapshot && ! $snapshot->isRetryDue( time() ) ) || ! $this->canFetch() ) {
			return $snapshot;
		}

		if ( $this->takeLazyFetch() ) {
			return $this->build( $order, $record, $this->interactiveApi() );
		}

		$this->queue->schedule( DanfeSnapshotJobs::BUILD_HOOK, [ $order->get_id() ], time(), true );

		return $snapshot;
	}

	/**
	 * Readiness of the simplified DANFE of the order, from what is saved (never downloads).
	 *
	 * @return string READY, PENDING, UNAVAILABLE or NONE (no invoice that gets a DANFE).
	 */
	public function readiness( \WC_Order $order, InvoiceRecord $record ): string {
		if ( ! $this->isEligible( $record ) ) {
			return self::NONE;
		}

		return $this->readinessOf( $this->current( $order, $record ) );
	}

	/**
	 * Readiness told by a snapshot of an eligible invoice: pending while a read may still come.
	 *
	 * @return string READY, PENDING or UNAVAILABLE.
	 */
	public function readinessOf( ?DanfeSnapshot $snapshot ): string {
		if ( $snapshot && $snapshot->isReady() ) {
			return self::READY;
		}

		if ( ( $snapshot && $snapshot->isFinal() ) || ! $this->canFetch() ) {
			return self::UNAVAILABLE;
		}

		return self::PENDING;
	}

	/**
	 * Reads the snapshot of an eligible invoice now, as a background job does.
	 */
	public function buildInBackground( \WC_Order $order, InvoiceRecord $record ): DanfeSnapshot {
		return $this->build( $order, $record, $this->api );
	}

	/**
	 * Reads the snapshot of an eligible invoice for the backfill: in the background, with a shorter wait for the
	 * download.
	 */
	public function buildForBackfill( \WC_Order $order, InvoiceRecord $record ): DanfeSnapshot {
		return $this->build( $order, $record, $this->api->withDownloadTimeout( self::BACKFILL_TIMEOUT ) );
	}

	/**
	 * Background read of one order (a retry, or an order a print could not read in time).
	 */
	public function buildQueued( \WC_Order $order ): void {
		$record = $this->invoices->find( $order );

		if ( ! $record || ! $this->isEligible( $record ) || ! $this->canFetch() ) {
			return;
		}

		$snapshot = $this->current( $order, $record );

		if ( $snapshot && ( $snapshot->isReady() || ( $snapshot->isFinal() && ! $snapshot->isRetryDue( time() ) ) ) ) {
			return;
		}

		$this->buildInBackground( $order, $record );
	}

	/**
	 * Reads the XML of an invoice just authorized, before Correios Automático hears of it.
	 *
	 * @param int            $orderId
	 * @param array          $invoice Invoice record.
	 * @param \WC_Order|null $order   Order the record was saved on.
	 */
	public function onAuthorized( $orderId, $invoice = [], $order = null ): void {
		$order = $this->order( $orderId, $order );
		$record = $order ? $this->invoices->find( $order ) : null;

		if ( ! $record || ! $this->isEligible( $record ) || ! $this->canFetch() ) {
			return;
		}

		$snapshot = $this->current( $order, $record );

		if ( $snapshot && $snapshot->isReady() ) {
			return;
		}

		$this->build( $order, $record, $this->interactiveApi() );
	}

	/**
	 * A cancelled invoice has no DANFE to print.
	 *
	 * @param int            $orderId
	 * @param array          $invoice Invoice record.
	 * @param \WC_Order|null $order   Order the record was saved on.
	 */
	public function onCancelled( $orderId, $invoice = [], $order = null ): void {
		$order = $this->order( $orderId, $order );

		if ( ! $order ) {
			return;
		}

		$this->queue->unschedule( DanfeSnapshotJobs::BUILD_HOOK, [ $order->get_id() ] );
		$this->repository->delete( $order );
	}

	/**
	 * A pre-posting may be printed right away: the snapshot of an invoice that has none yet is read once more.
	 *
	 * @param int    $orderId
	 * @param object $prepost Pre-posting saved by Correios Automático.
	 */
	public function onPrepostCreated( $orderId, $prepost = null ): void {
		// A return (reverse logistics) prints no DANFE.
		if ( is_object( $prepost ) && isset( $prepost->reverse_logistic ) && $prepost->reverse_logistic === 'S' ) {
			return;
		}

		$order = $this->order( $orderId, null );
		$record = $order ? $this->invoices->find( $order ) : null;

		if ( ! $record || ! $this->isEligible( $record ) || ! $this->canFetch() || $this->current( $order, $record ) ) {
			return;
		}

		$this->build( $order, $record, $this->interactiveApi() );
	}

	/**
	 * Downloads and reads the XML of the invoice and saves the snapshot, or the failure with its next attempt.
	 *
	 * @param InvoiceApi $api Background API, or the interactive one when a request waits on it.
	 */
	private function build( \WC_Order $order, InvoiceRecord $record, InvoiceApi $api ): DanfeSnapshot {
		$previous = $this->current( $order, $record );
		$file = $api->download( $order->get_id(), InvoiceApi::FILE_XML, $record->id() );

		if ( is_wp_error( $file ) ) {
			return $this->fail( $order, $record, $previous, $this->downloadError( $file ) );
		}

		$fields = $this->parser->parse( (string) $file['body'], $record->key() );

		if ( is_wp_error( $fields ) ) {
			return $this->fail( $order, $record, $previous, (string) $fields->get_error_code() );
		}

		$snapshot = DanfeSnapshot::ready( $record, $fields );
		$this->repository->save( $order, $snapshot );
		$this->queue->unschedule( DanfeSnapshotJobs::BUILD_HOOK, [ $order->get_id() ] );

		return $snapshot;
	}

	/**
	 * Saves a failed read; a failure a later attempt may overcome gets its retry queued, with the waits of the
	 * automatic emission.
	 *
	 * @param DanfeSnapshot|null $previous Snapshot of the same invoice before this read.
	 * @param string             $error    One of the DanfeSnapshot::ERROR_* codes.
	 */
	private function fail( \WC_Order $order, InvoiceRecord $record, ?DanfeSnapshot $previous, string $error ): DanfeSnapshot {
		$attempts = ( $previous && $previous->isFailed() ? $previous->attempts() : 0 ) + 1;
		$delays = InvoiceService::RETRY_DELAYS;
		$retry_at = in_array( $error, DanfeSnapshot::RETRYABLE_ERRORS, true ) && $attempts <= count( $delays ) ? time() + $delays[ $attempts - 1 ] : 0;

		$snapshot = DanfeSnapshot::failed( $record, $error, $attempts, $retry_at );
		$this->repository->save( $order, $snapshot );

		// Queued from inside a running build job too, which still counts as a job with the same arguments.
		$this->queue->unschedule( DanfeSnapshotJobs::BUILD_HOOK, [ $order->get_id() ] );

		if ( $retry_at > 0 ) {
			$this->queue->schedule( DanfeSnapshotJobs::BUILD_HOOK, [ $order->get_id() ], $retry_at );
		}

		return $snapshot;
	}

	private function downloadError( \WP_Error $error ): string {
		if ( ApiErrorMessage::status( $error ) === 404 || in_array( ApiErrorMessage::code( $error ), self::UNAVAILABLE_CODES, true ) ) {
			return DanfeSnapshot::ERROR_XML_UNAVAILABLE;
		}

		return DanfeSnapshot::ERROR_REQUEST_FAILED;
	}

	/**
	 * Takes one read of the print-time budget of the request, when any is left.
	 */
	private function takeLazyFetch(): bool {
		$now = microtime( true );

		if ( $this->lazyStartedAt === null ) {
			$this->lazyStartedAt = $now;
		}

		if ( $this->lazyFetches >= $this->lazyFetchLimit() || $now - $this->lazyStartedAt >= self::LAZY_FETCH_SECONDS ) {
			return false;
		}

		$this->lazyFetches++;

		return true;
	}

	private function lazyFetchLimit(): int {
		/**
		 * Filters how many simplified DANFE snapshots a print may read from SquidCore in one request; the other
		 * orders print as "being prepared" and are read in the background.
		 *
		 * @since 1.4.0
		 *
		 * @param int $limit Reads per request, 3 by default (they share 8 seconds).
		 */
		return max( 0, (int) apply_filters( 'infixs_squidcore_danfe_lazy_fetch_limit', self::LAZY_FETCH_LIMIT ) );
	}

	/**
	 * One attempt with a short wait, for a request that waits on the download.
	 */
	private function interactiveApi(): InvoiceApi {
		return $this->api->interactive()->withDownloadTimeout( self::INTERACTIVE_TIMEOUT );
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
}
