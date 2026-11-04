<?php

namespace Infixs\SquidCore\Http\Controllers\Api\V1;

use Infixs\SquidCore\AvelPress\Routing\Controller;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit\PlanLimitStatus;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportService;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCoverageStatus;
use Infixs\SquidCore\Services\ConnectionService;
use Infixs\SquidCore\Services\OAuthConnectService;
use Infixs\SquidCore\Services\SyncService;

defined( 'ABSPATH' ) || exit;

/**
 * Connection, sync and invoice status shown on the dashboard. Local data only, the dashboard asks on every page; the
 * availability of "Conectar com SquidCore" (checked on this site, SquidCore is not asked) only while the store has to
 * connect. Also the notices of the fiscal import, the cached count of products without NCM (never counted here) and
 * the invoice limit of the plan (the use of the month as last read, never read here).
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class StatusV1Controller extends Controller {

	public function show() {
		$connection = \infixs_squidcore_app( ConnectionService::class )->status();
		$offers_oauth = ! $connection['configured'] || $connection['needsReconnect'];

		return [
			'data' => [
				'connection' => $connection,
				'oauth' => $offers_oauth ? \infixs_squidcore_app( OAuthConnectService::class )->availability() : null,
				'sync' => \infixs_squidcore_app( SyncService::class )->overview(),
				'invoices' => \infixs_squidcore_app( InvoiceService::class )->overview(),
				'fiscalImport' => \infixs_squidcore_app( FiscalImportService::class )->summary( get_current_user_id() ),
				'ncmCoverage' => \infixs_squidcore_app( NcmCoverageStatus::class )->current(),
				'planLimit' => \infixs_squidcore_app( PlanLimitStatus::class )->current(),
			],
		];
	}
}
