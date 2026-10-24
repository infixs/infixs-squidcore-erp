<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceSettings;
use Infixs\SquidCore\Services\ConnectionService;

defined( 'ABSPATH' ) || exit;

/**
 * How many products still lack an NCM, as the admin app shows it: the cached count, the products list that shows
 * them and what the AI of SquidCore can do about them. Reads options only, never counts.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class NcmCoverageStatus {

	/**
	 * No AI connected in SquidCore: the app offers to connect one.
	 */
	public const AI_UNAVAILABLE = 'unavailable';

	/**
	 * An AI is connected, the automatic filling of the NCM is off.
	 */
	public const AI_OFF = 'off';

	public const AI_ON = 'on';

	/**
	 * @var NcmCoverage
	 */
	protected $coverage;

	/**
	 * @var MissingNcmProductsView
	 */
	protected $view;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	/**
	 * @var InvoiceSettings
	 */
	protected $invoices;

	public function __construct( NcmCoverage $coverage, MissingNcmProductsView $view, ConnectionService $connection, InvoiceSettings $invoices ) {
		$this->coverage = $coverage;
		$this->view = $view;
		$this->connection = $connection;
		$this->invoices = $invoices;
	}

	/**
	 * The status, after queuing the first count of a store never counted.
	 *
	 * @return array{total: int, withNcm: int, withoutNcm: int, computedAt: string|null, computing: bool, stale: bool, listUrl: string, ai: string}
	 */
	public function current(): array {
		$this->coverage->ensureScheduled();

		return array_merge( $this->coverage->summary(), [
			'listUrl' => $this->view->url(),
			'ai' => $this->ai(),
		] );
	}

	/**
	 * Asks for a new count now (the "Refresh" button) and returns the status, computing.
	 *
	 * @return array{total: int, withNcm: int, withoutNcm: int, computedAt: string|null, computing: bool, stale: bool, listUrl: string, ai: string}
	 */
	public function refresh(): array {
		$this->coverage->scheduleRecompute( 0 );

		return $this->current();
	}

	private function ai(): string {
		if ( ! $this->connection->aiNcmAvailable() ) {
			return self::AI_UNAVAILABLE;
		}

		return $this->invoices->autoFillNcmWithAi() ? self::AI_ON : self::AI_OFF;
	}
}
