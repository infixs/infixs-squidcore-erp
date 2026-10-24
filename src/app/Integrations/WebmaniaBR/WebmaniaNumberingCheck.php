<?php

namespace Infixs\SquidCore\Integrations\WebmaniaBR;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\NfeNumberingApi;
use Infixs\SquidCore\Services\ConnectionService;
use Infixs\SquidCore\Support\ApiErrorMessage;
use Infixs\SquidCore\Support\SquidCoreUrls;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the last NF-e numbers WebmaniaBR used with the next ones SquidCore would use, so a store coming from
 * WebmaniaBR continues the numbering instead of issuing a number SEFAZ already has (rejection for duplicity).
 *
 * The plugin only warns and links to the numbering screen of SquidCore: the WebmaniaBR numbers are a suggestion to
 * check in the Webmania panel (its history keeps no environment and misses the invoices issued in the panel).
 * The result is cached for 10 minutes; a failure to read SquidCore is not cached.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class WebmaniaNumberingCheck {

	public const TRANSIENT = 'infixs_squidcore_fiscal_import_numbering';

	public const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * @var WebmaniaInvoiceHistory
	 */
	protected $history;

	/**
	 * @var WebmaniaBR
	 */
	protected $webmania;

	/**
	 * @var NfeNumberingApi
	 */
	protected $api;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	/**
	 * @var SquidCoreUrls
	 */
	protected $urls;

	public function __construct( WebmaniaInvoiceHistory $history, WebmaniaBR $webmania, NfeNumberingApi $api, ConnectionService $connection, SquidCoreUrls $urls ) {
		$this->history = $history;
		$this->webmania = $webmania;
		$this->api = $api;
		$this->connection = $connection;
		$this->urls = $urls;
	}

	/**
	 * @param bool $fresh Skip the cache.
	 *
	 * @return array The NumberingCheck of the admin app: {webmania: {series, homologationLikely, ordersScanned},
	 *               squidcore: {series}|null, squidcoreError, alerts, companyDefaults, webmaniaDefaults, numberingUrl,
	 *               fiscalSettingsUrl, checkedAt}.
	 */
	public function check( bool $fresh = false ): array {
		$connected = $this->connection->isConnected();
		$cached = $fresh ? false : get_transient( self::TRANSIENT );

		// An answer from before the store connected (or disconnected) is not reused.
		if ( is_array( $cached ) && array_key_exists( 'squidcore', $cached ) && ( $cached['squidcore'] !== null ) === $connected ) {
			return $cached;
		}

		$webmania = $this->history->lastNumbers();
		$squidcore = null;
		$error = null;
		$defaults = null;

		if ( $connected ) {
			$series = $this->api->series();

			if ( is_wp_error( $series ) ) {
				$error = ApiErrorMessage::describe( $series );
			} else {
				$squidcore = [ 'series' => $series ];
				$company = $this->api->fiscalDefaults();
				$defaults = is_wp_error( $company ) ? null : $company;
			}
		}

		$app_url = $this->urls->appUrl();
		$check = [
			'webmania' => [
				'series' => $webmania['series'],
				'homologationLikely' => $this->webmania->environment() === WebmaniaBR::ENVIRONMENT_HOMOLOGATION,
				'ordersScanned' => $webmania['ordersScanned'],
			],
			'squidcore' => $squidcore,
			'squidcoreError' => $error,
			'alerts' => $squidcore === null ? [] : $this->alerts( $webmania['series'], $squidcore['series'] ),
			'companyDefaults' => $defaults,
			'webmaniaDefaults' => [
				'operationNature' => $this->webmania->operationNature(),
				'origin' => $this->webmania->defaultOrigin(),
			],
			'numberingUrl' => $app_url . '/settings/nfe-numeracao',
			'fiscalSettingsUrl' => $app_url . '/settings/fiscal',
			'checkedAt' => gmdate( 'c' ),
		];

		if ( $error === null ) {
			set_transient( self::TRANSIENT, $check, self::TTL );
		}

		return $check;
	}

	/**
	 * The WebmaniaBR series whose next SquidCore number is not above the last WebmaniaBR one, a series SquidCore does
	 * not have yet included (its first NF-e would be no. 1).
	 *
	 * @param array $webmania  [{cnpj, series, lastNumber}]
	 * @param array $squidcore [{series, nextNumber, configured, lastIssued}]
	 *
	 * @return array<int, array{cnpj: string, series: string, webmaniaLast: int, squidcoreNext: int, configured: bool}>
	 */
	private function alerts( array $webmania, array $squidcore ): array {
		$by_series = [];

		foreach ( $squidcore as $row ) {
			$by_series[ (int) $row['series'] ] = $row;
		}

		$alerts = [];

		foreach ( $webmania as $row ) {
			$series = $by_series[ (int) $row['series'] ] ?? null;
			$next = $series !== null ? (int) $series['nextNumber'] : 1;

			if ( $next <= (int) $row['lastNumber'] ) {
				$alerts[] = [
					'cnpj' => $row['cnpj'],
					'series' => $row['series'],
					'webmaniaLast' => (int) $row['lastNumber'],
					'squidcoreNext' => $next,
					'configured' => $series !== null && $series['configured'],
				];
			}
		}

		return $alerts;
	}
}
