<?php

namespace Infixs\SquidCore\Integrations\WooCommerce;

use Infixs\SquidCore\Support\FreshOption;

defined( 'ABSPATH' ) || exit;

/**
 * Counters per day of what the store did with SquidCore: pushes sent and lost for good, invoices authorized, cancelled
 * and left waiting for the store owner. The activity chart of the dashboard reads them.
 *
 * One option holds the last 90 days, keyed by the day in the timezone of the store, the day the store owner sees on
 * the chart: a push at 23:30 in São Paulo belongs to that day, not to the next one as UTC would say.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class ActivityRepository {

	public const OPTION = 'infixs_squidcore_activity';

	/**
	 * Days kept; older counters go away on every write.
	 */
	public const RETENTION_DAYS = 90;

	public const SENT = 'sent';

	public const FAILED = 'failed';

	public const INVOICES_AUTHORIZED = 'invoicesAuthorized';

	public const INVOICES_CANCELLED = 'invoicesCancelled';

	public const INVOICES_NEEDING_ACTION = 'invoicesNeedingAction';

	public const METRICS = [ self::SENT, self::FAILED, self::INVOICES_AUTHORIZED, self::INVOICES_CANCELLED, self::INVOICES_NEEDING_ACTION ];

	/**
	 * Adds to a counter of today.
	 *
	 * @param string $metric One of METRICS; anything else is dropped, so a typo never grows the option.
	 * @param int $by
	 */
	public function increment( string $metric, int $by = 1 ): void {
		if ( $by <= 0 || ! in_array( $metric, self::METRICS, true ) ) {
			return;
		}

		$today = $this->todayKey();
		$days = $this->prune( $this->read(), $today );
		$day = $days[ $today ] ?? $this->zeros();
		$day[ $metric ] += $by;
		$days[ $today ] = $day;

		update_option( self::OPTION, $days, false );
	}

	/**
	 * Counters of today.
	 *
	 * @return array{sent: int, failed: int, invoicesAuthorized: int, invoicesCancelled: int, invoicesNeedingAction: int}
	 */
	public function today(): array {
		return $this->read()[ $this->todayKey() ] ?? $this->zeros();
	}

	/**
	 * The last days, oldest first, every day present (zeros on the days nothing happened).
	 *
	 * @param int $count Number of days ending today, 1 to RETENTION_DAYS.
	 *
	 * @return array<int, array{date: string, sent: int, failed: int, invoicesAuthorized: int, invoicesCancelled: int, invoicesNeedingAction: int}>
	 */
	public function days( int $count ): array {
		$count = max( 1, min( self::RETENTION_DAYS, $count ) );
		$stored = $this->read();
		$series = [];

		foreach ( $this->dayKeys( $this->todayKey(), $count ) as $date ) {
			$series[] = array_merge( [ 'date' => $date ], $stored[ $date ] ?? $this->zeros() );
		}

		return $series;
	}

	/**
	 * Sum of each counter over a series of days().
	 *
	 * @param array[] $days
	 *
	 * @return array{sent: int, failed: int, invoicesAuthorized: int, invoicesCancelled: int, invoicesNeedingAction: int}
	 */
	public function totals( array $days ): array {
		$totals = $this->zeros();

		foreach ( $days as $day ) {
			foreach ( self::METRICS as $metric ) {
				$totals[ $metric ] += (int) ( $day[ $metric ] ?? 0 );
			}
		}

		return $totals;
	}

	/**
	 * Timezone the days are counted in, as the store sets it: a name such as America/Sao_Paulo, or a UTC offset.
	 */
	public function timezone(): string {
		return wp_timezone_string();
	}

	/**
	 * Forgets every counter: the activity belongs to the connection it happened through.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Today in the timezone of the store.
	 */
	private function todayKey(): string {
		return (string) wp_date( 'Y-m-d' );
	}

	/**
	 * The days ending on a day, oldest first. Calendar arithmetic on the key itself, in UTC, so a daylight saving
	 * change of the store never skips or repeats a day.
	 *
	 * @param string $last Y-m-d of the newest day.
	 * @param int $count
	 *
	 * @return string[] Y-m-d keys.
	 */
	private function dayKeys( string $last, int $count ): array {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $last, new \DateTimeZone( 'UTC' ) );
		$keys = [];

		for ( $back = $count - 1; $back >= 0; $back-- ) {
			$keys[] = $day->sub( new \DateInterval( 'P' . $back . 'D' ) )->format( 'Y-m-d' );
		}

		return $keys;
	}

	/**
	 * Drops the days older than the retention window, counted back from today.
	 *
	 * @param array<string, array> $days
	 * @param string $today Y-m-d.
	 *
	 * @return array<string, array>
	 */
	private function prune( array $days, string $today ): array {
		$oldest = $this->dayKeys( $today, self::RETENTION_DAYS )[0];

		// Y-m-d keys compare as text.
		return array_filter( $days, function ( $date ) use ( $oldest ) {
			return $date >= $oldest;
		}, ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Stored counters by day, reduced to well-formed rows. Read past the object cache: a long background batch would
	 * otherwise keep writing over what other runners counted since it started.
	 *
	 * @return array<string, array{sent: int, failed: int, invoicesAuthorized: int, invoicesCancelled: int, invoicesNeedingAction: int}>
	 */
	private function read(): array {
		$stored = FreshOption::get( self::OPTION );
		$days = [];

		if ( ! is_array( $stored ) ) {
			return $days;
		}

		foreach ( $stored as $date => $counters ) {
			if ( ! is_array( $counters ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
				continue;
			}

			$days[ $date ] = $this->row( $counters );
		}

		ksort( $days );

		return $days;
	}

	/**
	 * A row with every metric as a non-negative integer, the known metrics only.
	 */
	private function row( array $counters ): array {
		$row = $this->zeros();

		foreach ( self::METRICS as $metric ) {
			$row[ $metric ] = max( 0, (int) ( $counters[ $metric ] ?? 0 ) );
		}

		return $row;
	}

	private function zeros(): array {
		return array_fill_keys( self::METRICS, 0 );
	}
}
