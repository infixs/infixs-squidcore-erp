<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Background jobs of the invoices: the automatic emission and the status checks.
 *
 * The handlers must be registered on every request (front, admin, cron), otherwise queued jobs would never run.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceJobs {

	public const EMIT_HOOK = 'infixs_squidcore_invoice_emit';

	public const POLL_HOOK = 'infixs_squidcore_invoice_poll';

	/**
	 * @var InvoiceService
	 */
	protected $service;

	/**
	 * @var InvoiceSettings
	 */
	protected $settings;

	/**
	 * @var InvoiceEligibility
	 */
	protected $eligibility;

	public function __construct( InvoiceService $service, InvoiceSettings $settings, InvoiceEligibility $eligibility ) {
		$this->service = $service;
		$this->settings = $settings;
		$this->eligibility = $eligibility;
	}

	public function registerHandlers(): void {
		add_action( self::EMIT_HOOK, [ $this, 'handleEmit' ] );
		add_action( self::POLL_HOOK, [ $this, 'handlePoll' ] );
	}

	/**
	 * Automatic emission. Runs only for an order still waiting for it, still paid, with the option still on and
	 * nothing new ruling it out (an invoice another plugin issued meanwhile, for example).
	 *
	 * @param int $order_id
	 */
	public function handleEmit( $order_id ): void {
		$order = $this->order( $order_id );
		$record = $order ? $this->service->find( $order ) : null;

		if ( ! $order || ! $record || ! $record->is( InvoiceRecord::SCHEDULED ) ) {
			return;
		}

		if ( ! $this->eligibility->isStillPaid( $order ) ) {
			$this->service->skip( $order, __( 'Automatic invoice skipped: the order is no longer paid.', 'infixs-squidcore-erp' ) );
			return;
		}

		if ( ! $this->settings->isEnabled() ) {
			$this->service->skip( $order, __( 'Automatic invoice skipped: the automatic emission was turned off.', 'infixs-squidcore-erp' ) );
			return;
		}

		$reason = $this->eligibility->emitTimeSkipReason( $order );

		if ( $reason !== null ) {
			$this->service->skip( $order, $reason );
			return;
		}

		$this->service->emit( $order, InvoiceService::TRIGGER_AUTOMATIC );
	}

	/**
	 * @param int $order_id
	 */
	public function handlePoll( $order_id ): void {
		$order = $this->order( $order_id );

		if ( $order ) {
			$this->service->poll( $order );
		}
	}

	/**
	 * @param mixed $order_id
	 */
	private function order( $order_id ): ?\WC_Order {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( (int) $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}
}
