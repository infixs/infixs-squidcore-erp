<?php

namespace Infixs\SquidCore\Support;

defined( 'ABSPATH' ) || exit;

/**
 * How much work one background job may do before it hands over to its next run: a number of seconds and a share of
 * the PHP memory limit (without a limit, that share of 256 MB on top of what the request used when the job started).
 * A job loops over its batches while the budget lasts, so a store whose cron runs one job a minute still finishes in
 * a few runs instead of one batch per run.
 *
 * Between batches, releaseRuntimeCache() drops the objects the batch put in the in-memory cache, when the object
 * cache allows it without touching a persistent store.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
final class JobBudget {

	private const MEMORY_WITHOUT_LIMIT = 268435456;

	/**
	 * @var float
	 */
	private $startedAt;

	/**
	 * @var int
	 */
	private $seconds;

	/**
	 * Memory use (bytes) at which the budget ends.
	 *
	 * @var float
	 */
	private $memoryCeiling;

	/**
	 * @param int   $seconds     Seconds of work (0: one unit of work only).
	 * @param float $memoryShare Share of the memory limit the job may reach.
	 */
	public function __construct( int $seconds, float $memoryShare ) {
		$this->startedAt = microtime( true );
		$this->seconds = max( 0, $seconds );

		$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;
		$this->memoryCeiling = $limit > 0 ? $limit * $memoryShare : memory_get_usage() + self::MEMORY_WITHOUT_LIMIT * $memoryShare;
	}

	/**
	 * The time is up or the memory reached its share.
	 */
	public function exhausted(): bool {
		return $this->elapsed() >= $this->seconds || memory_get_usage() >= $this->memoryCeiling;
	}

	/**
	 * Seconds since the budget started.
	 */
	public function elapsed(): float {
		return microtime( true ) - $this->startedAt;
	}

	/**
	 * Empties the in-memory object cache between two batches. A persistent object cache that cannot flush only its
	 * in-memory part is left alone (the memory share then ends the loop).
	 */
	public static function releaseRuntimeCache(): void {
		if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}
}
