<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRecord;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRepository;
use Infixs\SquidCore\Support\SquidCoreUrls;

defined( 'ABSPATH' ) || exit;

/**
 * The invoice limit of the SquidCore plan as the admin app shows it: the orders left without an invoice by the limit,
 * the re-emission of them, the use of the month and the plans of the panel. Never calls SquidCore (the use comes from
 * its last read).
 *
 * @package Infixs\SquidCore
 * @since   1.5.0
 */
class PlanLimitStatus {

	/**
	 * @var InvoiceRepository
	 */
	protected $repository;

	/**
	 * @var PlanLimitRetry
	 */
	protected $retry;

	/**
	 * @var InvoiceUsage
	 */
	protected $usage;

	/**
	 * @var SquidCoreUrls
	 */
	protected $urls;

	public function __construct( InvoiceRepository $repository, PlanLimitRetry $retry, InvoiceUsage $usage, SquidCoreUrls $urls ) {
		$this->repository = $repository;
		$this->retry = $retry;
		$this->usage = $usage;
		$this->urls = $urls;
	}

	/**
	 * @return array{failed: int, plansUrl: string, retry: array, usage: array|null}
	 */
	public function current(): array {
		return $this->with( $this->usage->current() );
	}

	/**
	 * The status with the use read from SquidCore now ("Refresh").
	 *
	 * @return array See current().
	 */
	public function refreshUsage(): array {
		return $this->with( $this->usage->refreshNow() );
	}

	/**
	 * @param array|null $usage InvoiceUsage::current().
	 *
	 * @return array See current().
	 */
	private function with( ?array $usage ): array {
		return [
			'failed' => $this->repository->countByFailure( InvoiceRecord::FAILURE_PLAN_LIMIT ),
			'plansUrl' => $this->urls->plansUrl(),
			'retry' => $this->retry->status(),
			'usage' => $usage,
		];
	}
}
