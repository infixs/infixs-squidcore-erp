<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceApi;
use Infixs\SquidCore\Services\ConnectionService;
use Infixs\SquidCore\Support\ApiErrorMessage;
use Infixs\SquidCore\Support\BackgroundQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Invoices of the month against the limit of the SquidCore plan ("8 of 10"), read from SquidCore in the background
 * and kept for the screens, which never call SquidCore while a page loads.
 *
 * A screen reading a use older than 10 minutes, or of a month already over, queues a new read; an authorized invoice
 * and a 402 of the plan limit queue one too, and "Refresh" reads it at once. Shown only for an account whose limits
 * SquidCore applies (ENFORCED): hidden when the plan has no limit, when the limits are off (OFF) or only measured
 * (SHADOW, where nothing the customer sees may change), and when SquidCore does not have the route (an older version
 * answers 404, a token without the NF-e scopes 403), which is then read again only every 6 hours. Any other failure
 * keeps the last use and tries again every 10 minutes, but a use stops showing once its month is over or a day after
 * it was read. The use belongs to the connection it was read through: it is forgotten when the store points to
 * another one.
 *
 * @package Infixs\SquidCore
 * @since   1.5.0
 */
class InvoiceUsage {

	public const HOOK = 'infixs_squidcore_invoice_usage_refresh';

	/**
	 * Last read: {status: ok|unsupported, usage: {used, limit, enforcement, periodStart, periodEnd}|null, checkedAt,
	 * attemptedAt}. `checkedAt` is when SquidCore gave that answer, `attemptedAt` the last try, failed ones included.
	 */
	public const TRANSIENT = 'infixs_squidcore_invoice_usage';

	/**
	 * Share of the limit from which the screens warn that the month is running out.
	 */
	public const NEAR_SHARE = 0.8;

	public const LEVEL_OK = 'ok';

	public const LEVEL_NEAR = 'near';

	public const LEVEL_REACHED = 'reached';

	private const STATUS_OK = 'ok';

	private const STATUS_UNSUPPORTED = 'unsupported';

	private const METRIC = 'taxInvoicesPerMonth';

	/**
	 * The only mode in which SquidCore refuses an invoice over the limit (402), so the only one the screens show.
	 */
	private const ENFORCED = 'ENFORCED';

	private const ENFORCEMENTS = [ self::ENFORCED, 'SHADOW', 'OFF' ];

	/**
	 * Seconds between two reads of the use.
	 */
	private const FRESH_FOR = 10 * MINUTE_IN_SECONDS;

	/**
	 * Seconds before a SquidCore without the route is asked again.
	 */
	private const UNSUPPORTED_FRESH_FOR = 6 * HOUR_IN_SECONDS;

	/**
	 * Seconds a use read from SquidCore is shown while the new reads fail.
	 */
	private const KEEP_FOR = DAY_IN_SECONDS;

	/**
	 * Seconds between an authorized invoice and the read it queues, so a burst of authorizations costs one read.
	 */
	private const AFTER_INVOICE_DELAY = 15;

	/**
	 * @var InvoiceApi
	 */
	protected $api;

	/**
	 * @var ConnectionService
	 */
	protected $connection;

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	public function __construct( InvoiceApi $api, ConnectionService $connection, BackgroundQueue $queue ) {
		$this->api = $api;
		$this->connection = $connection;
		$this->queue = $queue;
	}

	/**
	 * The job handler and the triggers must be registered on every request (front, admin, cron).
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'refreshInBackground' ] );
		add_action( 'infixs_squidcore_invoice_authorized', [ $this, 'refreshSoon' ] );
		add_action( 'infixs_squidcore_invoice_plan_limit_reached', [ $this, 'refreshSoon' ] );
		add_action( 'infixs_squidcore_connection_changed', [ $this, 'forget' ] );
	}

	/**
	 * The use for the screens, from the last read only; queues a new read when it is old.
	 *
	 * @return array{used: int, limit: int, remaining: int, level: string, periodStart: string|null, periodEnd: string|null, checkedAt: string}|null
	 *         Null when hidden: not read yet, no limit, limits not applied to the account, SquidCore without the
	 *         route, or the last use is of a month already over or too old.
	 */
	public function current(): ?array {
		if ( ! $this->canRead() ) {
			return null;
		}

		$entry = $this->entry();

		if ( $this->isStale( $entry ) ) {
			$this->queue->scheduleBy( self::HOOK, [], time(), PlanLimitRetry::PRIORITY );
		}

		return $this->present( $entry );
	}

	/**
	 * Reads the use now, for "Refresh" (one attempt, the person waits for it).
	 *
	 * @return array|null See current().
	 */
	public function refreshNow(): ?array {
		if ( ! $this->canRead() ) {
			return null;
		}

		return $this->present( $this->read( $this->api->interactive() ) );
	}

	/**
	 * Job: reads the use from SquidCore.
	 */
	public function refreshInBackground(): void {
		if ( $this->canRead() ) {
			$this->read( $this->api );
		}
	}

	/**
	 * An invoice was authorized or refused by the plan limit: the use changed in SquidCore.
	 */
	public function refreshSoon(): void {
		if ( $this->canRead() ) {
			$this->queue->scheduleBy( self::HOOK, [], time() + self::AFTER_INVOICE_DELAY, PlanLimitRetry::PRIORITY );
		}
	}

	/**
	 * The store points to another connection (new credential, other API, none): the use read through the previous
	 * one is of another account, or of another SquidCore.
	 */
	public function forget(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * The route takes the NF-e scopes: a token known to lack them would only get a 403.
	 */
	private function canRead(): bool {
		return $this->connection->isConnected() && $this->connection->invoicePermission() !== false;
	}

	/**
	 * Reads the use and keeps the outcome.
	 *
	 * @return array The entry kept.
	 */
	private function read( InvoiceApi $api ): array {
		$response = $api->usage();
		$entry = $this->entry();
		$now = time();

		if ( ! is_wp_error( $response ) ) {
			$usage = $this->parse( $response );
			$entry = [
				'status' => $usage !== null ? self::STATUS_OK : self::STATUS_UNSUPPORTED,
				'usage' => $usage,
				'checkedAt' => $now,
			];
		} elseif ( in_array( ApiErrorMessage::status( $response ), [ 403, 404 ], true ) ) {
			$entry = [
				'status' => self::STATUS_UNSUPPORTED,
				'usage' => null,
				'checkedAt' => $now,
			];
		}

		// Any other failure keeps the last answer as it was read (present() drops it once too old) and waits as long
		// as an answer read now would.
		$entry['attemptedAt'] = $now;
		set_transient( self::TRANSIENT, $entry, self::KEEP_FOR );

		return $entry;
	}

	/**
	 * @param array $data The `data` of the answer.
	 *
	 * @return array{used: int, limit: int|null, enforcement: string, periodStart: string, periodEnd: string}|null
	 *         Null when the answer is not the use of the monthly invoices.
	 */
	private function parse( array $data ): ?array {
		$enforcement = strtoupper( is_string( $data['enforcement'] ?? null ) ? $data['enforcement'] : '' );

		if ( ( $data['metric'] ?? '' ) !== self::METRIC || ! isset( $data['used'] ) || ! is_numeric( $data['used'] ) || ! in_array( $enforcement, self::ENFORCEMENTS, true ) ) {
			return null;
		}

		return [
			'used' => max( 0, (int) $data['used'] ),
			'limit' => isset( $data['limit'] ) && is_numeric( $data['limit'] ) ? max( 0, (int) $data['limit'] ) : null,
			'enforcement' => $enforcement,
			'periodStart' => is_string( $data['periodStart'] ?? null ) ? sanitize_text_field( $data['periodStart'] ) : '',
			'periodEnd' => is_string( $data['periodEnd'] ?? null ) ? sanitize_text_field( $data['periodEnd'] ) : '',
		];
	}

	/**
	 * @return array|null See current().
	 */
	private function present( array $entry ): ?array {
		$usage = $entry['usage'] ?? null;

		if ( ( $entry['status'] ?? '' ) !== self::STATUS_OK || ! is_array( $usage ) || $usage['limit'] === null || $usage['enforcement'] !== self::ENFORCED ) {
			return null;
		}

		if ( $this->isPeriodOver( $usage ) || time() - (int) ( $entry['checkedAt'] ?? 0 ) >= self::KEEP_FOR ) {
			return null;
		}

		$used = (int) $usage['used'];
		$limit = (int) $usage['limit'];

		return [
			'used' => $used,
			'limit' => $limit,
			'remaining' => max( 0, $limit - $used ),
			'level' => $this->level( $used, $limit ),
			'periodStart' => $usage['periodStart'] !== '' ? (string) $usage['periodStart'] : null,
			'periodEnd' => $usage['periodEnd'] !== '' ? (string) $usage['periodEnd'] : null,
			'checkedAt' => gmdate( 'c', (int) $entry['checkedAt'] ),
		];
	}

	private function level( int $used, int $limit ): string {
		if ( $used >= $limit ) {
			return self::LEVEL_REACHED;
		}

		return $used >= $limit * self::NEAR_SHARE ? self::LEVEL_NEAR : self::LEVEL_OK;
	}

	/**
	 * Whether a new read is due: none yet, the last try is old, or the month of the use ended after it.
	 */
	private function isStale( array $entry ): bool {
		$attempted = (int) ( $entry['attemptedAt'] ?? 0 );

		if ( $attempted === 0 ) {
			return true;
		}

		$period_end = $this->periodEnd( $entry['usage'] ?? null );

		if ( $period_end !== null && $period_end <= time() && $attempted < $period_end ) {
			return true;
		}

		$fresh_for = ( $entry['status'] ?? '' ) === self::STATUS_UNSUPPORTED ? self::UNSUPPORTED_FRESH_FOR : self::FRESH_FOR;

		return time() - $attempted >= $fresh_for;
	}

	/**
	 * Whether the month of the use is over: the quota of the plan started again.
	 */
	private function isPeriodOver( array $usage ): bool {
		$period_end = $this->periodEnd( $usage );

		return $period_end !== null && $period_end <= time();
	}

	/**
	 * @param mixed $usage The use kept.
	 *
	 * @return int|null Unix time the month of the use ends, null when SquidCore did not tell it.
	 */
	private function periodEnd( $usage ): ?int {
		$end = is_array( $usage ) && is_string( $usage['periodEnd'] ?? null ) && $usage['periodEnd'] !== '' ? strtotime( $usage['periodEnd'] ) : false;

		return $end !== false ? $end : null;
	}

	/**
	 * @return array The last read, empty before the first.
	 */
	private function entry(): array {
		$entry = get_transient( self::TRANSIENT );

		return is_array( $entry ) ? $entry : [];
	}
}
