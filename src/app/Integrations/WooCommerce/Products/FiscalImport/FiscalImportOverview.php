<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosAutomatico;
use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaBR;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCode;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCoverageStatus;

defined( 'ABSPATH' ) || exit;

/**
 * What the admin app shows of the import: the whole picture for the Import tab (TS FiscalImportOverview) and the
 * small block of /status for the notices (TS FiscalImportStatus). Local data only; reading it may queue the scan.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportOverview {

	/**
	 * @var WebmaniaBR
	 */
	protected $webmania;

	/**
	 * @var FiscalImportRunRepository
	 */
	protected $runs;

	/**
	 * @var FiscalImportScanner
	 */
	protected $scanner;

	/**
	 * @var FiscalImportPusher
	 */
	protected $pusher;

	/**
	 * @var FiscalImportWatchdog
	 */
	protected $watchdog;

	/**
	 * @var FiscalImportSuggestion
	 */
	protected $suggestion;

	/**
	 * @var FiscalImportNoticeBoard
	 */
	protected $notices;

	/**
	 * @var CorreiosAutomatico
	 */
	protected $correios;

	/**
	 * @var NcmCoverageStatus
	 */
	protected $coverage;

	public function __construct( WebmaniaBR $webmania, FiscalImportRunRepository $runs, FiscalImportScanner $scanner, FiscalImportPusher $pusher, FiscalImportWatchdog $watchdog, FiscalImportSuggestion $suggestion, FiscalImportNoticeBoard $notices, CorreiosAutomatico $correios, NcmCoverageStatus $coverage ) {
		$this->webmania = $webmania;
		$this->runs = $runs;
		$this->scanner = $scanner;
		$this->pusher = $pusher;
		$this->watchdog = $watchdog;
		$this->suggestion = $suggestion;
		$this->notices = $notices;
		$this->correios = $correios;
		$this->coverage = $coverage;
	}

	/**
	 * The Import tab. Restarts a stalled run, queues the scan when the probe found data and none was made, and takes
	 * the "import finished" notice away from the one who started the import.
	 */
	public function overview( int $userId ): array {
		$this->watchdog->check();
		$found = $this->webmania->probe()['found'];

		if ( $found ) {
			$this->scanner->ensureScheduled();
		}

		$this->notices->acknowledgeDone( $userId );

		$state = $this->runs->state();
		$coverage = $this->coverage->current();

		return [
			'source' => $this->webmania->toArray(),
			'found' => $found,
			'scan' => $this->scan(),
			'scanning' => $this->scanner->progress(),
			'globalNcm' => $this->globalNcm(),
			'defaults' => FiscalImportChoices::defaults( $this->correios->isActive() )->toArray(),
			'run' => $state['run'] !== null ? FiscalImportRun::toPublic( $state['run'] ) : null,
			'last' => $state['last'] !== null ? FiscalImportRun::toPublic( $state['last'] ) : null,
			'push' => $this->pusher->overview(),
			'coverage' => $coverage,
			'checks' => [
				'doubleEmission' => $this->notices->doubleEmission(),
				'checkoutFields' => $this->webmania->providesCheckoutFields(),
			],
			'correiosActive' => $this->correios->isActive(),
			'urls' => [
				'plugins' => admin_url( 'plugins.php' ),
				'productsWithoutNcm' => $coverage['listUrl'],
			],
		];
	}

	/**
	 * The /status block. Queues the scan when the probe found data, none was made and the NCM import is not done yet
	 * (afterwards only the Import tab asks for one).
	 *
	 * @return array{sourceState: string, suggest: bool, candidates: int|null, running: array|null, notices: array}
	 */
	public function status( int $userId ): array {
		if ( $this->suggestion->hasSource() && $this->runs->snapshot()['completed']['ncm'] === null && $this->webmania->probe()['found'] ) {
			$this->scanner->ensureScheduled();
		}

		$running = $this->suggestion->runningRun();

		return [
			'sourceState' => $this->webmania->state(),
			'suggest' => $this->suggestion->isDue(),
			'candidates' => $this->suggestion->candidates(),
			'running' => $running === null ? null : [
				'runId' => $running['id'],
				'mode' => $running['mode'],
				'processed' => $running['processed'],
				'total' => $running['total'],
			],
			'notices' => $this->notices->due( $userId ),
		];
	}

	/**
	 * The last scan result (TS FiscalImportScan), null while there is none.
	 */
	private function scan(): ?array {
		$scan = $this->runs->scan();

		if ( $scan === null ) {
			return null;
		}

		return [
			'candidates' => (int) ( $scan['candidates'] ?? 0 ),
			'productsWithNcm' => (int) ( $scan['productsWithNcm'] ?? 0 ),
			'categoriesWithNcm' => (int) ( $scan['categoriesWithNcm'] ?? 0 ),
			'products' => (int) ( $scan['products'] ?? 0 ),
			'counts' => FiscalImportCounts::normalize( $scan['counts'] ?? [] ),
			'finishedAt' => gmdate( 'c', (int) ( $scan['finishedAt'] ?? 0 ) ),
		];
	}

	/**
	 * The store-wide NCM of WebmaniaBR, formatted when valid; null when unset (no digit at all).
	 *
	 * @return array{ncm: string, valid: bool}|null
	 */
	private function globalNcm(): ?array {
		$raw = trim( $this->webmania->globalNcm() );
		$digits = NcmCode::normalize( $raw );

		if ( $digits === '' ) {
			return null;
		}

		$valid = NcmCode::isValid( $digits );

		return [
			'ncm' => $valid ? NcmCode::format( $digits ) : $raw,
			'valid' => $valid,
		];
	}
}
