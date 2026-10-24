<?php

namespace Infixs\SquidCore\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Background jobs of the plugin.
 *
 * Runs through Action Scheduler when it is available (WooCommerce ships it) and falls back to WP-Cron.
 * Every job of the plugin lives in the same Action Scheduler group.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class BackgroundQueue {

	public const GROUP = 'infixs-squidcore';

	/**
	 * Queues a single run of a hook.
	 *
	 * @param string $hook
	 * @param array $args Positional arguments given to the hook callbacks.
	 * @param int $timestamp Unix time of the run.
	 * @param bool $unique Skip when a pending or running job has the same hook and arguments. A job that queues
	 *                     its own next run must pass false: while it runs, it counts as a job with the same arguments.
	 * @param int $priority Action Scheduler priority (0 to 255, lower runs first among the jobs due). WP-Cron has no
	 *                      priority and ignores it.
	 */
	public function schedule( string $hook, array $args, int $timestamp, bool $unique = false, int $priority = 10 ): void {
		if ( $unique && $this->isScheduled( $hook, $args ) ) {
			return;
		}

		if ( $this->isActionSchedulerAvailable() ) {
			as_schedule_single_action( $timestamp, $hook, $args, self::GROUP, $unique, $priority );
			return;
		}

		wp_schedule_single_event( $timestamp, $hook, $args );
	}

	/**
	 * Makes sure one pending job of the hook and arguments runs by $timestamp: a pending job due later is moved to
	 * $timestamp (a job debounced for minutes that is asked for now runs now), one due by then is kept. A job running
	 * right now does not count, since it may have started before the reason for this run.
	 *
	 * @param string $hook
	 * @param array $args Positional arguments given to the hook callbacks.
	 * @param int $timestamp Unix time the run is due by.
	 * @param int $priority See schedule().
	 */
	public function scheduleBy( string $hook, array $args, int $timestamp, int $priority = 10 ): void {
		$next = $this->nextPendingRun( $hook, $args );

		if ( $next !== null && $next <= $timestamp ) {
			return;
		}

		if ( $next !== null ) {
			$this->unschedule( $hook, $args );
		}

		$this->schedule( $hook, $args, $timestamp, false, $priority );
	}

	/**
	 * Unix time of the earliest pending job with the hook and arguments (a running one left out), null when none.
	 */
	public function nextPendingRun( string $hook, array $args ): ?int {
		if ( ! $this->isActionSchedulerAvailable() || ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) ) {
			$next = wp_next_scheduled( $hook, $args );

			return is_int( $next ) ? $next : null;
		}

		$ids = as_get_scheduled_actions( [
			'hook' => $hook,
			'args' => $args,
			'group' => self::GROUP,
			'status' => \ActionScheduler_Store::STATUS_PENDING,
			'orderby' => 'date',
			'order' => 'ASC',
			'per_page' => 1,
		], 'ids' );

		if ( ! $ids ) {
			return null;
		}

		try {
			$date = \ActionScheduler::store()->get_date( (int) reset( $ids ) );
		} catch ( \Exception $e ) {
			// Run or removed by another process in the meantime.
			return null;
		}

		return $date instanceof \DateTime ? $date->getTimestamp() : null;
	}

	/**
	 * Whether a job with the hook and arguments is pending (or running, with Action Scheduler).
	 */
	public function isScheduled( string $hook, array $args ): bool {
		if ( $this->isActionSchedulerAvailable() ) {
			return false !== as_next_scheduled_action( $hook, $args, self::GROUP );
		}

		return false !== wp_next_scheduled( $hook, $args );
	}

	/**
	 * Removes the pending jobs with the hook and arguments.
	 */
	public function unschedule( string $hook, array $args ): void {
		if ( $this->isActionSchedulerAvailable() && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, $args, self::GROUP );
			return;
		}

		wp_clear_scheduled_hook( $hook, $args );
	}

	/**
	 * Number of pending jobs of a hook, whatever their arguments.
	 */
	public function pendingCount( string $hook ): int {
		if ( $this->isActionSchedulerAvailable() && class_exists( 'ActionScheduler' ) ) {
			return (int) \ActionScheduler::store()->query_actions( [
				'hook' => $hook,
				'group' => self::GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			], 'count' );
		}

		$count = 0;

		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ $hook ] ) && is_array( $events[ $hook ] ) ) {
				$count += count( $events[ $hook ] );
			}
		}

		return $count;
	}

	/**
	 * Unix time of the next pending run of the hook and arguments, null when none.
	 */
	public function nextRun( string $hook, array $args ): ?int {
		if ( $this->isActionSchedulerAvailable() ) {
			$next = as_next_scheduled_action( $hook, $args, self::GROUP );

			// True means the job is running right now.
			return is_int( $next ) ? $next : null;
		}

		$next = wp_next_scheduled( $hook, $args );

		return is_int( $next ) ? $next : null;
	}

	protected function isActionSchedulerAvailable(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' );
	}
}
