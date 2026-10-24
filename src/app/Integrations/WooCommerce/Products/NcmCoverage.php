<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products;

use Infixs\SquidCore\Support\BackgroundQueue;
use Infixs\SquidCore\Support\FreshOption;
use Infixs\SquidCore\Support\JobBudget;

defined( 'ABSPATH' ) || exit;

/**
 * How many products have an NCM that can go on the NF-e, counted in the background, never while a page loads.
 *
 * A product has an NCM when its effective NCM (the SquidCore meta when it exists, even blank; otherwise the Correios
 * Automático one) passes the structural rules of NcmCode. Published and private products count; grouped and external
 * ones are left out (WooCommerce does not sell them directly), and an NCM saved only on a variation does not count.
 *
 * The count is three aggregated queries, each driven by an index: the products that count (posts), the ones with a
 * valid SquidCore NCM and the ones with a valid Correios NCM and no SquidCore meta (both through the meta key index,
 * so only the rows of these keys are read, never every meta of every product). Products without NCM = the first
 * minus the other two. A job runs them and goes on in its next run after about 20 seconds. Changes to the NCM, the
 * status or the type of a product mark the count as stale; only the first mark queues a new count, 5 minutes later,
 * so a burst of edits costs one count. A count asked for now (the end of an import, the refresh button) moves that
 * debounced count up instead of waiting for it. The result stays in an option the screens read.
 *
 * Known divergence from NcmCode: MySQL 5.7 has no REGEXP_REPLACE, so only dots, spaces and dashes are removed in SQL;
 * an NCM typed with another separator counts as missing here.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class NcmCoverage {

	public const HOOK = 'infixs_squidcore_ncm_coverage';

	/**
	 * Result: {total, withoutNcm, computedAt (unix), durationMs, run: {part, total, withOwn, withCorreios, startedAt,
	 * elapsedMs}|null, countedFrom, requestedAt}. Not autoloaded. countedFrom is when the last finished count started
	 * and requestedAt when a new count was last asked for (microtime): a request after countedFrom is still pending.
	 */
	public const OPTION = 'infixs_squidcore_ncm_coverage';

	/**
	 * '1' while a product changed after the count (autoloaded: checking it costs nothing).
	 */
	public const DIRTY_OPTION = 'infixs_squidcore_ncm_coverage_dirty';

	/**
	 * Comment that marks the counting queries, and only them (written as is in countPart()).
	 */
	public const MARKER = '/* isq_ncm_coverage */';

	/**
	 * Seconds between a change and the count it queues.
	 */
	public const DEBOUNCE = 300;

	/**
	 * Seconds of counting per run, before the job goes on in the next run.
	 */
	public const TIME_BUDGET = 20;

	/**
	 * The queries of a count, in order: products that count, with a valid SquidCore NCM, with a valid Correios NCM
	 * and no SquidCore meta.
	 */
	public const PARTS = [ 'total', 'withOwn', 'withCorreios' ];

	/**
	 * Seconds before the next run of an unfinished count.
	 */
	private const NEXT_DELAY = 5;

	/**
	 * Statuses that count.
	 */
	private const STATUSES = [ 'publish', 'private' ];

	/**
	 * Product types left out.
	 */
	private const LEFT_OUT_TYPES = [ 'grouped', 'external' ];

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	/**
	 * Pauses in this request (pauseTracking() calls not resumed yet).
	 *
	 * @var int
	 */
	private $paused = 0;

	/**
	 * Term taxonomy ids of the product types left out, read once per request.
	 *
	 * @var int[]|null
	 */
	private $leftOutTypes = null;

	public function __construct( BackgroundQueue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * The job handler and the change triggers must be registered on every request (front, admin, cron).
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'compute' ] );
		add_action( 'added_post_meta', [ $this, 'onMetaChange' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'onMetaChange' ], 10, 3 );
		add_action( 'deleted_post_meta', [ $this, 'onMetaChange' ], 10, 3 );
		add_action( 'transition_post_status', [ $this, 'onStatusChange' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'onDeletedPost' ], 10, 2 );
		add_action( 'set_object_terms', [ $this, 'onTermsChange' ], 10, 6 );
	}

	/**
	 * Cached count, read from the options only.
	 *
	 * @return array{total: int, withNcm: int, withoutNcm: int, computedAt: string|null, computing: bool, stale: bool}
	 *         computing: a count is running or was asked for and has not finished; stale: a product changed since
	 *         the count (a new one is queued).
	 */
	public function summary(): array {
		$state = $this->state( get_option( self::OPTION, [] ) );
		$total = $state['total'];
		$without = min( $total, $state['withoutNcm'] );

		return [
			'total' => $total,
			'withNcm' => $total - $without,
			'withoutNcm' => $without,
			'computedAt' => $state['computedAt'] > 0 ? gmdate( 'c', $state['computedAt'] ) : null,
			'computing' => $state['run'] !== null || $state['requestedAt'] > $state['countedFrom'],
			'stale' => get_option( self::DIRTY_OPTION, '' ) === '1',
		];
	}

	/**
	 * Products without NCM in the last count; 0 before the first one.
	 */
	public function withoutNcm(): int {
		return $this->summary()['withoutNcm'];
	}

	/**
	 * Queues the first count of the store, once: does nothing after the first count was asked for.
	 */
	public function ensureScheduled(): void {
		if ( get_option( self::OPTION, null ) !== null ) {
			return;
		}

		$this->scheduleRecompute( 0 );
	}

	/**
	 * Asks for a new count from scratch (refresh button, end of an import), which shows as computing until it ends.
	 * A count waiting for the debounce of a product change is moved up, so the one asked for runs in $delay seconds.
	 */
	public function scheduleRecompute( int $delay = 0 ): void {
		$state = $this->state( FreshOption::get( self::OPTION ) );
		$state['requestedAt'] = microtime( true );
		$this->save( $state );

		$this->queue->scheduleBy( self::HOOK, [], time() + max( 0, $delay ) );
	}

	/**
	 * Marks the count as stale and queues a new one in DEBOUNCE seconds. Only the first mark does anything; while the
	 * mark is there, this is the read of an autoloaded option.
	 */
	public function markDirty(): void {
		if ( $this->paused > 0 || get_option( self::DIRTY_OPTION, '' ) === '1' ) {
			return;
		}

		update_option( self::DIRTY_OPTION, '1', true );
		$this->queue->schedule( self::HOOK, [], time() + self::DEBOUNCE, true );
	}

	/**
	 * Stops marking the count as stale in this request (an import writing many products asks for one count at the
	 * end instead). Pauses nest.
	 */
	public function pauseTracking(): void {
		++$this->paused;
	}

	public function resumeTracking(): void {
		$this->paused = max( 0, $this->paused - 1 );
	}

	/**
	 * @param int|int[] $metaId
	 * @param int       $objectId
	 * @param string    $metaKey
	 */
	public function onMetaChange( $metaId, $objectId, $metaKey ): void {
		if ( ! in_array( $metaKey, [ ProductNcmStore::META_KEY, ProductNcmStore::CORREIOS_META_KEY ], true ) ) {
			return;
		}

		if ( get_post_type( (int) $objectId ) === 'product' ) {
			$this->markDirty();
		}
	}

	/**
	 * Creation, publication, trash and restore of a product.
	 *
	 * @param string   $newStatus
	 * @param string   $oldStatus
	 * @param \WP_Post $post
	 */
	public function onStatusChange( $newStatus, $oldStatus, $post ): void {
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'product' || $newStatus === $oldStatus ) {
			return;
		}

		if ( in_array( $newStatus, self::STATUSES, true ) || in_array( $oldStatus, self::STATUSES, true ) ) {
			$this->markDirty();
		}
	}

	/**
	 * @param int           $postId
	 * @param \WP_Post|null $post
	 */
	public function onDeletedPost( $postId, $post = null ): void {
		if ( $post instanceof \WP_Post && $post->post_type === 'product' && in_array( $post->post_status, self::STATUSES, true ) ) {
			$this->markDirty();
		}
	}

	/**
	 * A product type change (grouped and external products are left out).
	 *
	 * @param int    $objectId
	 * @param array  $terms
	 * @param array  $termTaxonomyIds
	 * @param string $taxonomy
	 * @param bool   $append
	 * @param array  $oldTermTaxonomyIds
	 */
	public function onTermsChange( $objectId, $terms, $termTaxonomyIds, $taxonomy, $append = false, $oldTermTaxonomyIds = [] ): void {
		if ( $taxonomy !== 'product_type' ) {
			return;
		}

		$new = array_map( 'intval', (array) $termTaxonomyIds );
		$old = array_map( 'intval', (array) $oldTermTaxonomyIds );
		sort( $new );
		sort( $old );

		if ( $new !== $old && get_post_type( (int) $objectId ) === 'product' ) {
			$this->markDirty();
		}
	}

	/**
	 * Job: runs the queries of the count for about TIME_BUDGET seconds, then saves where it stopped and goes on in the
	 * next run. A count asked for after this one started starts over.
	 */
	public function compute(): void {
		delete_option( self::DIRTY_OPTION );

		$state = $this->state( FreshOption::get( self::OPTION ) );
		$run = $state['run'];

		if ( $run === null || $state['requestedAt'] > $run['startedAt'] ) {
			$run = [
				'part' => 0,
				'total' => 0,
				'withOwn' => 0,
				'withCorreios' => 0,
				'startedAt' => microtime( true ),
				'elapsedMs' => 0,
			];
		}

		$budget = new JobBudget( self::TIME_BUDGET, 1.0 );

		while ( $run['part'] < count( self::PARTS ) ) {
			$part = self::PARTS[ $run['part'] ];
			$run[ $part ] = $this->countPart( $part );
			++$run['part'];

			if ( $budget->exhausted() ) {
				break;
			}
		}

		$run['elapsedMs'] += (int) round( $budget->elapsed() * 1000 );

		// Keeps a request for a new count that arrived while this run worked.
		$state['requestedAt'] = $this->state( FreshOption::get( self::OPTION ) )['requestedAt'];

		if ( $run['part'] < count( self::PARTS ) ) {
			$state['run'] = $run;
			$this->save( $state );
			$this->queueNext( self::NEXT_DELAY );

			return;
		}

		$state['total'] = $run['total'];
		$state['withoutNcm'] = max( 0, $run['total'] - $run['withOwn'] - $run['withCorreios'] );
		$state['computedAt'] = time();
		$state['durationMs'] = $run['elapsedMs'];
		$state['countedFrom'] = $run['startedAt'];
		$state['run'] = null;
		$this->save( $state );

		if ( $state['requestedAt'] > $run['startedAt'] ) {
			$this->queueNext( self::NEXT_DELAY );
		} elseif ( FreshOption::get( self::DIRTY_OPTION ) === '1' ) {
			$this->queueNext( self::DEBOUNCE );
		}
	}

	/**
	 * The count of the products with an id in ( $afterId, $upToId ], all the queries at once (used to check a part of
	 * the catalog).
	 *
	 * @return array{total: int, withoutNcm: int}
	 */
	public function countRange( int $afterId, int $upToId ): array {
		$counts = [];

		foreach ( self::PARTS as $part ) {
			$counts[ $part ] = $this->countPart( $part, [ $afterId, $upToId ] );
		}

		return [
			'total' => $counts['total'],
			'withoutNcm' => max( 0, $counts['total'] - $counts['withOwn'] - $counts['withCorreios'] ),
		];
	}

	/**
	 * One query of the count, marked with MARKER.
	 *
	 * @param string     $part   PARTS item.
	 * @param int[]|null $bounds Ids ( after, up to ], null for the whole catalog.
	 */
	public function countPart( string $part, ?array $bounds = null ): int {
		global $wpdb;

		$sql = $this->sql( 'p' );
		$range = $bounds !== null ? $wpdb->prepare( ' AND p.ID > %d AND p.ID <= %d', (int) $bounds[0], (int) $bounds[1] ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- background count; the fragments come from sql() (constants, prepared meta keys and integer ids) and the bounds were prepared above.
		switch ( $part ) {
			case 'withOwn':
				$query = $wpdb->prepare(
					"SELECT /* isq_ncm_coverage */ COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m STRAIGHT_JOIN {$wpdb->posts} p ON p.ID = m.post_id
					WHERE m.meta_key = %s AND " . $this->validity( $this->normalized( 'm.meta_value' ) ) . " AND {$sql['where']}{$range}",
					ProductNcmStore::META_KEY
				);
				break;

			case 'withCorreios':
				$query = $wpdb->prepare(
					"SELECT /* isq_ncm_coverage */ COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m STRAIGHT_JOIN {$wpdb->posts} p ON p.ID = m.post_id
					WHERE m.meta_key = %s AND " . $this->validity( $this->normalized( 'm.meta_value' ) ) . " AND {$sql['where']} AND p.ID NOT IN ({$sql['ownExists']}){$range}",
					ProductNcmStore::CORREIOS_META_KEY
				);
				break;

			default:
				$query = "SELECT /* isq_ncm_coverage */ COUNT(*) FROM {$wpdb->posts} p WHERE {$sql['where']}{$range}";
		}

		$count = (int) $wpdb->get_var( $query );
		// phpcs:enable

		return $count;
	}

	/**
	 * The rule as SQL fragments over the posts table (or alias) given, shared by the count and by the products list:
	 * - where: the products that count (type and status);
	 * - missing: the products without a valid effective NCM, through the meta key index (no join with the metas);
	 * - ownExists: the ids with a SquidCore meta (even blank).
	 *
	 * @return array{where: string, missing: string, ownExists: string}
	 */
	public function sql( string $posts = 'p' ): array {
		global $wpdb;

		$where = "{$posts}.post_type = 'product' AND {$posts}.post_status IN ('publish', 'private')";
		$left_out = $this->leftOutTypes();

		if ( $left_out ) {
			$where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} isq_tr WHERE isq_tr.object_id = {$posts}.ID AND isq_tr.term_taxonomy_id IN (" . implode( ',', $left_out ) . '))';
		}

		$valid_own = $this->metaIds( 'isq_vo', ProductNcmStore::META_KEY, true );
		$valid_correios = $this->metaIds( 'isq_vc', ProductNcmStore::CORREIOS_META_KEY, true );
		$own_exists = $this->metaIds( 'isq_eo', ProductNcmStore::META_KEY, false );

		return [
			'where' => $where,
			'missing' => "{$posts}.ID NOT IN ({$valid_own}) AND NOT ({$posts}.ID IN ({$valid_correios}) AND {$posts}.ID NOT IN ({$own_exists}))",
			'ownExists' => $own_exists,
		];
	}

	/**
	 * Ids with a row of the meta key (only the valid NCM ones when asked), as a subquery.
	 */
	private function metaIds( string $alias, string $key, bool $validOnly ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and alias given by this plugin.
		$sql = $wpdb->prepare( "SELECT {$alias}.post_id FROM {$wpdb->postmeta} {$alias} WHERE {$alias}.meta_key = %s", $key );

		return $validOnly ? $sql . ' AND ' . $this->validity( $this->normalized( "{$alias}.meta_value" ) ) : $sql;
	}

	/**
	 * The NCM as stored, without dots, spaces and dashes.
	 */
	private function normalized( string $column ): string {
		return "REPLACE(REPLACE(REPLACE(TRIM({$column}), '.', ''), ' ', ''), '-', '')";
	}

	/**
	 * NcmCode rules in SQL: 8 digits, chapter 01 to 97 but 77 (which also rules out 00000000).
	 */
	private function validity( string $ncm ): string {
		return "({$ncm} REGEXP '^[0-9]{8}$' AND LEFT({$ncm}, 2) BETWEEN '01' AND '97' AND LEFT({$ncm}, 2) <> '77')";
	}

	/**
	 * Term taxonomy ids of the grouped and external product types (none before WooCommerce created them).
	 *
	 * @return int[]
	 */
	private function leftOutTypes(): array {
		if ( $this->leftOutTypes !== null ) {
			return $this->leftOutTypes;
		}

		$this->leftOutTypes = [];

		foreach ( self::LEFT_OUT_TYPES as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_type' );

			if ( $term instanceof \WP_Term ) {
				$this->leftOutTypes[] = (int) $term->term_taxonomy_id;
			}
		}

		return $this->leftOutTypes;
	}

	/**
	 * Queues the next run from inside a run (the running job does not count), unless one is pending by then.
	 */
	private function queueNext( int $delay ): void {
		$this->queue->scheduleBy( self::HOOK, [], time() + $delay );
	}

	/**
	 * @param mixed $value Stored option.
	 *
	 * @return array{total: int, withoutNcm: int, computedAt: int, durationMs: int, run: array{part: int, total: int, withOwn: int, withCorreios: int, startedAt: float, elapsedMs: int}|null, countedFrom: float, requestedAt: float}
	 */
	private function state( $value ): array {
		$value = is_array( $value ) ? $value : [];
		$run = isset( $value['run'] ) && is_array( $value['run'] ) ? $value['run'] : null;

		return [
			'total' => max( 0, (int) ( $value['total'] ?? 0 ) ),
			'withoutNcm' => max( 0, (int) ( $value['withoutNcm'] ?? 0 ) ),
			'computedAt' => max( 0, (int) ( $value['computedAt'] ?? 0 ) ),
			'durationMs' => max( 0, (int) ( $value['durationMs'] ?? 0 ) ),
			'run' => $run === null ? null : [
				'part' => min( count( self::PARTS ), max( 0, (int) ( $run['part'] ?? 0 ) ) ),
				'total' => max( 0, (int) ( $run['total'] ?? 0 ) ),
				'withOwn' => max( 0, (int) ( $run['withOwn'] ?? 0 ) ),
				'withCorreios' => max( 0, (int) ( $run['withCorreios'] ?? 0 ) ),
				'startedAt' => (float) ( $run['startedAt'] ?? 0 ),
				'elapsedMs' => max( 0, (int) ( $run['elapsedMs'] ?? 0 ) ),
			],
			'countedFrom' => (float) ( $value['countedFrom'] ?? 0 ),
			'requestedAt' => (float) ( $value['requestedAt'] ?? 0 ),
		];
	}

	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
