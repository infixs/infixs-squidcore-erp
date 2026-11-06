<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

defined( 'ABSPATH' ) || exit;

/**
 * The AI filling of the NCM as the screens see it (the invoice settings and the bulk action of the products list): how
 * many products it would ask for, the start, stop and resume, the progress and the lists to review.
 *
 * Only one filling runs at a time. Errors come back as WP_Error with the REST status.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class NcmAiFillService {

	/**
	 * @var NcmAiFillRepository
	 */
	protected $runs;

	/**
	 * @var NcmAiFillRunner
	 */
	protected $runner;

	/**
	 * @var NcmAiFillTargets
	 */
	protected $targets;

	/**
	 * @var NcmAiAvailability
	 */
	protected $ai;

	public function __construct( NcmAiFillRepository $runs, NcmAiFillRunner $runner, NcmAiFillTargets $targets, NcmAiAvailability $ai ) {
		$this->runs = $runs;
		$this->runner = $runner;
		$this->targets = $targets;
		$this->ai = $ai;
	}

	/**
	 * The /status block (TS NcmAiFillStatus). A run that stopped by itself gets a job again.
	 *
	 * @return array{available: bool, run: array|null}
	 */
	public function status(): array {
		$run = $this->runs->snapshot();

		if ( $run !== null ) {
			$this->runner->ensureQueued( $run );
		}

		return [
			'available' => $this->ai->isAvailable(),
			'run' => $run !== null ? NcmAiFillRun::toPublic( $run ) : null,
		];
	}

	/**
	 * Whether the store can start a filling now: the AI is there and no filling runs.
	 */
	public function canStart(): bool {
		return $this->ai->isAvailable() && ! NcmAiFillRun::isRunning( $this->runs->snapshot() );
	}

	/**
	 * How many products the AI would be asked for (the ones without NCM, counted now): among the chosen ones, or among
	 * every product.
	 *
	 * @param int[]|null $ids Products chosen; null for every product.
	 *
	 * @return array{selected: int|null, targets: int} selected: null for every product.
	 */
	public function preview( ?array $ids ): array {
		if ( $ids === null ) {
			return [
				'selected' => null,
				'targets' => $this->targets->count(),
			];
		}

		$ids = self::ids( $ids );

		return [
			'selected' => count( $ids ),
			'targets' => $ids ? $this->targets->count( $ids ) : 0,
		];
	}

	/**
	 * Starts filling every product without NCM, or only the chosen ones without NCM.
	 *
	 * @param int[]|null $ids Products chosen (bulk action); null for every product without NCM.
	 *
	 * @return array|\WP_Error The run (TS NcmAiFillRun).
	 */
	public function start( ?array $ids, \WP_User $user ) {
		$unavailable = NcmAiErrors::unavailability( $this->ai );

		if ( $unavailable !== null ) {
			return NcmAiErrors::make( $unavailable, 409 );
		}

		if ( NcmAiFillRun::isRunning( $this->runs->run() ) ) {
			return NcmAiErrors::make( NcmAiErrors::BUSY, 409 );
		}

		$only = $ids === null ? null : self::ids( $ids );
		$total = $only === [] ? 0 : $this->targets->count( $only );

		if ( $total === 0 ) {
			return NcmAiErrors::make( NcmAiErrors::NOTHING, 409 );
		}

		$run = NcmAiFillRun::create( $only, $total, $user );
		$busy = false;

		$this->runs->update( function ( ?array $current ) use ( $run, &$busy ): ?array {
			if ( NcmAiFillRun::isRunning( $current ) ) {
				$busy = true;
				return null;
			}

			return $run;
		} );

		if ( $busy ) {
			return NcmAiErrors::make( NcmAiErrors::BUSY, 409 );
		}

		$this->runs->resetItems( $run['id'] );
		$this->runner->schedule( $run['id'] );

		return NcmAiFillRun::toPublic( $run );
	}

	/**
	 * Stops the filling; what it filled stays. A batch on its way still saves what the AI answered.
	 *
	 * @return array|\WP_Error
	 */
	public function stop( string $runId ) {
		$run = $this->find( $runId );

		if ( is_wp_error( $run ) || ! NcmAiFillRun::isRunning( $run ) ) {
			return is_wp_error( $run ) ? $run : NcmAiFillRun::toPublic( $run );
		}

		$saved = $this->runs->update( function ( ?array $current ) use ( $runId ): ?array {
			if ( ! NcmAiFillRun::isRunning( $current ) || $current['id'] !== $runId ) {
				return null;
			}

			$current['status'] = NcmAiFillRun::STOPPED;
			$current['finishedAt'] = time();

			return $current;
		} );

		$this->runner->unschedule( $runId );

		if ( $saved !== null && $saved['id'] === $runId && $saved['status'] === NcmAiFillRun::STOPPED ) {
			$this->runner->finish( $saved );
		}

		return $this->present( $runId );
	}

	/**
	 * Goes on with a stopped or failed filling, from where it stopped.
	 *
	 * @return array|\WP_Error
	 */
	public function resume( string $runId ) {
		$run = $this->find( $runId );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( ! in_array( $run['status'], [ NcmAiFillRun::STOPPED, NcmAiFillRun::FAILED ], true ) ) {
			return NcmAiErrors::make( NcmAiErrors::INVALID_STATE, 409 );
		}

		$unavailable = NcmAiErrors::unavailability( $this->ai );

		if ( $unavailable !== null ) {
			return NcmAiErrors::make( $unavailable, 409 );
		}

		$this->runs->update( function ( ?array $current ) use ( $runId ): ?array {
			if ( $current === null || $current['id'] !== $runId ) {
				return null;
			}

			$current['status'] = NcmAiFillRun::RUNNING;
			$current['error'] = null;
			$current['finishedAt'] = null;
			$current['retries'] = 0;
			$current['heartbeat'] = time();

			return $current;
		} );

		$this->runner->schedule( $runId );

		return $this->present( $runId );
	}

	/**
	 * A page of a list of the run.
	 *
	 * @param string $list NcmAiFillRepository::LISTS.
	 *
	 * @return array|\WP_Error {data, total, currentPage, perPage, lastPage}.
	 */
	public function items( string $runId, string $list, int $page, int $perPage ) {
		$items = $this->runs->items();

		if ( $items['runId'] !== $runId || ! in_array( $list, NcmAiFillRepository::LISTS, true ) ) {
			return NcmAiErrors::make( NcmAiErrors::RUN_NOT_FOUND, 404 );
		}

		$rows = $items[ $list ];
		$per_page = max( 1, $perPage );
		$page = max( 1, $page );
		$slice = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );

		_prime_post_caches( array_map( function ( $row ) {
			return (int) ( $row['productId'] ?? 0 );
		}, $slice ), false, false );

		return [
			'data' => array_map( function ( $row ) use ( $list ) {
				return NcmAiFillItems::present( $row, $list );
			}, $slice ),
			'total' => count( $rows ),
			'currentPage' => $page,
			'perPage' => $per_page,
			'lastPage' => max( 1, (int) ceil( count( $rows ) / $per_page ) ),
		];
	}

	/**
	 * @return array|\WP_Error The stored run with this id.
	 */
	private function find( string $runId ) {
		$run = $this->runs->run();

		return $run !== null && $run['id'] === $runId ? $run : NcmAiErrors::make( NcmAiErrors::RUN_NOT_FOUND, 404 );
	}

	/**
	 * @return array|\WP_Error
	 */
	private function present( string $runId ) {
		$run = $this->find( $runId );

		return is_wp_error( $run ) ? $run : NcmAiFillRun::toPublic( $run );
	}

	/**
	 * Positive ids, once each, in order, up to NcmAiFillRun::MAX_SELECTED.
	 *
	 * @param array $ids
	 *
	 * @return int[]
	 */
	private static function ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		sort( $ids );

		return array_slice( $ids, 0, NcmAiFillRun::MAX_SELECTED );
	}
}
