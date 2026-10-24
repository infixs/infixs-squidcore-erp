<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosAutomatico;
use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaBR;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCoverage;

defined( 'ABSPATH' ) || exit;

/**
 * The import of the fiscal data of another invoice plugin (WebmaniaBR), as the REST routes see it: what there is to
 * bring, a preview that writes nothing, the import, stop and resume, the push to SquidCore and the undo.
 *
 * Only one preview, import or undo runs at a time. Errors come back as WP_Error with the REST status.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportService {

	public const ERROR_NO_SOURCE = 'fiscal_import_no_source';

	public const ERROR_NOT_FOUND = 'fiscal_import_not_found';

	public const ERROR_BUSY = 'fiscal_import_busy';

	public const ERROR_LOCKED = 'fiscal_import_locked';

	public const ERROR_INVALID_STATE = 'fiscal_import_invalid_state';

	/**
	 * @var FiscalImportRunRepository
	 */
	protected $runs;

	/**
	 * @var FiscalImportRunner
	 */
	protected $runner;

	/**
	 * @var FiscalImportScanner
	 */
	protected $scanner;

	/**
	 * @var FiscalImportPusher
	 */
	protected $pusher;

	/**
	 * @var FiscalImportJournal
	 */
	protected $journal;

	/**
	 * @var FiscalImportOverview
	 */
	protected $overview;

	/**
	 * @var FiscalDataSource
	 */
	protected $source;

	/**
	 * @var WebmaniaBR
	 */
	protected $webmania;

	/**
	 * @var CorreiosAutomatico
	 */
	protected $correios;

	/**
	 * @var NcmCoverage
	 */
	protected $coverage;

	public function __construct( FiscalImportRunRepository $runs, FiscalImportRunner $runner, FiscalImportScanner $scanner, FiscalImportPusher $pusher, FiscalImportJournal $journal, FiscalImportOverview $overview, FiscalDataSource $source, WebmaniaBR $webmania, CorreiosAutomatico $correios, NcmCoverage $coverage ) {
		$this->runs = $runs;
		$this->runner = $runner;
		$this->scanner = $scanner;
		$this->pusher = $pusher;
		$this->journal = $journal;
		$this->overview = $overview;
		$this->source = $source;
		$this->webmania = $webmania;
		$this->correios = $correios;
		$this->coverage = $coverage;
	}

	/**
	 * TS FiscalImportOverview.
	 */
	public function overview( int $userId ): array {
		return $this->overview->overview( $userId );
	}

	/**
	 * The /status block (TS FiscalImportStatus).
	 */
	public function summary( int $userId ): array {
		return $this->overview->status( $userId );
	}

	/**
	 * Looks for WebmaniaBR data again: the probe and the scan start over.
	 */
	public function scan(): void {
		$this->webmania->forgetProbe();
		$this->scanner->restart();
	}

	/**
	 * Starts a preview with the choices sent (the missing ones take the defaults).
	 *
	 * @return array|\WP_Error The run (TS FiscalImportRun).
	 */
	public function preview( array $choices, \WP_User $user ) {
		if ( ! in_array( $this->webmania->state(), [ WebmaniaBR::STATE_ACTIVE, WebmaniaBR::STATE_INACTIVE_WITH_DATA ], true ) ) {
			return $this->error( self::ERROR_NO_SOURCE, 409 );
		}

		$defaults = FiscalImportChoices::defaults( $this->correios->isActive() );
		$run = FiscalImportRun::create( FiscalImportRun::MODE_PREVIEW, FiscalImportChoices::fromArray( $choices, $defaults )->toArray(), $this->source->countProducts(), null, $user );

		return $this->begin( $run, null );
	}

	/**
	 * Imports with the choices of a finished preview.
	 *
	 * @return array|\WP_Error
	 */
	public function start( string $runId, \WP_User $user ) {
		if ( FiscalImportRun::isRunning( $this->runs->state()['run'] ) ) {
			return $this->error( self::ERROR_BUSY, 409 );
		}

		$preview = $this->runs->find( $runId );

		if ( $preview === null ) {
			return $this->error( self::ERROR_NOT_FOUND, 404 );
		}

		if ( $preview['mode'] !== FiscalImportRun::MODE_PREVIEW || $preview['status'] !== FiscalImportRun::READY ) {
			return $this->error( self::ERROR_INVALID_STATE, 409 );
		}

		$run = FiscalImportRun::create( FiscalImportRun::MODE_APPLY, $preview['choices'], $this->source->countProducts(), $runId, $user );
		$run['coverageBefore'] = $this->coverage->summary()['computedAt'] !== null ? $this->coverage->withoutNcm() : null;

		return $this->begin( $run, $runId );
	}

	/**
	 * Runs one batch now, for the import screen.
	 *
	 * @return array|\WP_Error
	 */
	public function step( string $runId ) {
		$run = $this->runs->find( $runId );

		if ( $run === null ) {
			return $this->error( self::ERROR_NOT_FOUND, 404 );
		}

		if ( ! FiscalImportRun::isRunning( $run ) ) {
			return FiscalImportRun::toPublic( $run );
		}

		if ( $this->runner->runBatch( $runId ) === FiscalImportRunner::LOCKED ) {
			return $this->error( self::ERROR_LOCKED, 409 );
		}

		return $this->present( $runId );
	}

	/**
	 * Stops the run; what was done stays (an import stopped with writes can be undone).
	 *
	 * @return array|\WP_Error
	 */
	public function stop( string $runId ) {
		$run = $this->runs->find( $runId );

		if ( $run === null ) {
			return $this->error( self::ERROR_NOT_FOUND, 404 );
		}

		if ( ! FiscalImportRun::isRunning( $run ) ) {
			return FiscalImportRun::toPublic( $run );
		}

		$this->runs->update( function ( array $state ) use ( $runId ): array {
			if ( ! FiscalImportRun::isRunning( $state['run'] ) || $state['run']['id'] !== $runId ) {
				return $state;
			}

			$state['run']['status'] = FiscalImportRun::STOPPED;
			$state['run']['finishedAt'] = time();

			return FiscalImportRun::settle( $state );
		} );

		$this->runner->unschedule( $runId );

		if ( $run['mode'] !== FiscalImportRun::MODE_PREVIEW && $run['counts']['writes'] > 0 ) {
			$this->coverage->scheduleRecompute( 0 );
		}

		return $this->present( $runId );
	}

	/**
	 * Goes on with a stopped or failed run, from where it stopped.
	 *
	 * @return array|\WP_Error
	 */
	public function resume( string $runId ) {
		$run = $this->runs->find( $runId );

		if ( $run === null ) {
			return $this->error( self::ERROR_NOT_FOUND, 404 );
		}

		$current = $this->runs->state()['run'];

		if ( FiscalImportRun::isRunning( $current ) && $current['id'] !== $runId ) {
			return $this->error( self::ERROR_BUSY, 409 );
		}

		if ( $current === null || $current['id'] !== $runId || ! in_array( $current['status'], [ FiscalImportRun::STOPPED, FiscalImportRun::FAILED ], true ) ) {
			return $this->error( self::ERROR_INVALID_STATE, 409 );
		}

		$this->runs->update( function ( array $state ) use ( $runId ): array {
			if ( $state['run'] !== null && $state['run']['id'] === $runId ) {
				$state['run']['status'] = FiscalImportRun::RUNNING;
				$state['run']['error'] = null;
				$state['run']['finishedAt'] = null;
				$state['run']['heartbeat'] = time();
			}

			return $state;
		} );

		$this->runner->schedule( $runId );

		return $this->present( $runId );
	}

	/**
	 * Sends the NCM a run wrote (the last import by default) to SquidCore, now or as soon as the store can.
	 *
	 * @return array|\WP_Error TS FiscalImportPushResult.
	 */
	public function push( ?string $runId ) {
		$last = $this->runs->state()['last'];
		$run = $runId !== null ? $this->runs->find( $runId ) : $last;

		if ( $run === null ) {
			return $this->error( self::ERROR_NOT_FOUND, 404 );
		}

		if ( $run['mode'] === FiscalImportRun::MODE_PREVIEW ) {
			return $this->error( self::ERROR_INVALID_STATE, 409 );
		}

		return $this->pusher->request( $run['id'] );
	}

	/**
	 * Undoes the last import: its products get back the NCM they had, unless they were edited since.
	 *
	 * @return array|\WP_Error
	 */
	public function undo( string $runId, \WP_User $user ) {
		$state = $this->runs->state();
		$import = $this->runs->find( $runId );

		if ( $import === null ) {
			return $this->error( self::ERROR_NOT_FOUND, 404 );
		}

		if ( FiscalImportRun::isRunning( $state['run'] ) ) {
			return $this->error( self::ERROR_BUSY, 409 );
		}

		$last = $state['last'];

		if ( $last === null || $last['id'] !== $runId || $last['mode'] !== FiscalImportRun::MODE_APPLY || ! $last['undoable'] ) {
			return $this->error( self::ERROR_INVALID_STATE, 409 );
		}

		$run = FiscalImportRun::create( FiscalImportRun::MODE_UNDO, $last['choices'], $this->journal->count( $runId ), $runId, $user );

		return $this->begin( $run, null );
	}

	/**
	 * A page of a list of the most recent run.
	 *
	 * @param string $type FiscalImportItems::TYPES.
	 *
	 * @return array|\WP_Error {data, total, currentPage, perPage, lastPage, count}.
	 */
	public function items( string $runId, string $type, int $page, int $perPage ) {
		$items = $this->runs->items();

		if ( $items['runId'] !== $runId ) {
			return $this->error( self::ERROR_NOT_FOUND, 404 );
		}

		$rows = $items['lists'][ $type ] ?? [];
		$run = $this->runs->find( $runId );
		$per_page = max( 1, $perPage );

		return [
			'data' => array_map( [ FiscalImportItems::class, 'present' ], array_slice( $rows, ( max( 1, $page ) - 1 ) * $per_page, $per_page ) ),
			'total' => count( $rows ),
			'currentPage' => max( 1, $page ),
			'perPage' => $per_page,
			'lastPage' => max( 1, (int) ceil( count( $rows ) / $per_page ) ),
			'count' => $run !== null ? FiscalImportCounts::forList( $run['counts'], $type ) : count( $rows ),
		];
	}

	/**
	 * Makes the run the current one and queues its first batch, unless another run is under way.
	 *
	 * @param string|null $readyId Apply: the preview it comes from, which must still be the current one and ready.
	 *
	 * @return array|\WP_Error
	 */
	private function begin( array $run, ?string $readyId ) {
		$outcome = null;

		$this->runs->update( function ( array $state ) use ( $run, $readyId, &$outcome ): array {
			$current = $state['run'];

			if ( FiscalImportRun::isRunning( $current ) ) {
				$outcome = self::ERROR_BUSY;
				return $state;
			}

			if ( $readyId !== null && ( $current === null || $current['id'] !== $readyId || $current['status'] !== FiscalImportRun::READY ) ) {
				$outcome = self::ERROR_INVALID_STATE;
				return $state;
			}

			$state['run'] = $run;

			// The import is being undone: the suggestion may come back.
			if ( $run['mode'] === FiscalImportRun::MODE_UNDO ) {
				$state['completed']['ncm'] = null;
			}

			return $state;
		} );

		if ( $outcome !== null ) {
			return $this->error( $outcome, 409 );
		}

		$this->runs->resetItems( $run['id'] );
		$this->runner->schedule( $run['id'] );

		return FiscalImportRun::toPublic( $run );
	}

	/**
	 * @return array|\WP_Error
	 */
	private function present( string $runId ) {
		$run = $this->runs->find( $runId );

		return $run !== null ? FiscalImportRun::toPublic( $run ) : $this->error( self::ERROR_NOT_FOUND, 404 );
	}

	private function error( string $code, int $status ): \WP_Error {
		switch ( $code ) {
			case self::ERROR_NO_SOURCE:
				$message = __( 'There is no WebmaniaBR data to import.', 'infixs-squidcore-erp' );
				break;
			case self::ERROR_NOT_FOUND:
				$message = __( 'Import not found. Refresh the page.', 'infixs-squidcore-erp' );
				break;
			case self::ERROR_BUSY:
				$message = __( 'Another import is in progress. Wait for it to finish or stop it.', 'infixs-squidcore-erp' );
				break;
			case self::ERROR_LOCKED:
				$message = __( 'The import is processing a batch. Trying again in a moment.', 'infixs-squidcore-erp' );
				break;
			default:
				$message = __( 'This import can no longer do that. Refresh the page.', 'infixs-squidcore-erp' );
		}

		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
