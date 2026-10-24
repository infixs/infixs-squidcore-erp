<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaBR;

defined( 'ABSPATH' ) || exit;

/**
 * When the plugin suggests bringing the NCM codes of WebmaniaBR: the plugin is active or left data behind, there is
 * something to bring (the scan found candidates, or the quick probe found data before the scan finished), the NCM was
 * not imported yet and no run is under way. Dismissals are the notices' business.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportSuggestion {

	private const SOURCE_STATES = [ WebmaniaBR::STATE_ACTIVE, WebmaniaBR::STATE_INACTIVE_WITH_DATA ];

	/**
	 * @var WebmaniaBR
	 */
	protected $webmania;

	/**
	 * @var FiscalImportRunRepository
	 */
	protected $runs;

	public function __construct( WebmaniaBR $webmania, FiscalImportRunRepository $runs ) {
		$this->webmania = $webmania;
		$this->runs = $runs;
	}

	/**
	 * WebmaniaBR is active, or inactive with data left.
	 */
	public function hasSource(): bool {
		return in_array( $this->webmania->state(), self::SOURCE_STATES, true );
	}

	public function isDue(): bool {
		if ( ! $this->hasSource() ) {
			return false;
		}

		$state = $this->runs->snapshot();
		$scan = $this->runs->scan();
		$has_data = $scan !== null ? (int) ( $scan['candidates'] ?? 0 ) > 0 : $this->webmania->probe()['found'];

		return $has_data && $state['completed']['ncm'] === null && ! FiscalImportRun::isRunning( $state['run'] );
	}

	/**
	 * Products the import would give an NCM with the default choices; null until the scan finishes.
	 */
	public function candidates(): ?int {
		$scan = $this->runs->scan();

		return $scan !== null ? (int) ( $scan['candidates'] ?? 0 ) : null;
	}

	/**
	 * Whether the Import tab opens by itself after the activation: the quick probe found data and the NCM was not
	 * imported yet.
	 */
	public function opensImportTab(): bool {
		return $this->hasSource()
			&& $this->webmania->probe()['found']
			&& $this->runs->snapshot()['completed']['ncm'] === null;
	}

	/**
	 * The run under way, null when none.
	 */
	public function runningRun(): ?array {
		$run = $this->runs->snapshot()['run'];

		return FiscalImportRun::isRunning( $run ) ? $run : null;
	}
}
