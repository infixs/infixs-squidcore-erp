<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products;

use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosAutomatico;
use Infixs\SquidCore\Support\BackgroundQueue;
use Infixs\SquidCore\Support\FreshOption;
use Infixs\SquidCore\Support\JobBudget;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the SquidCore NCM in step with the Correios Automático one (ProductNcmStore::reconcile() has the rule):
 * - once, in the background, while Correios Automático is active, over every product with either NCM: after the
 *   update to this version, after each activation of SquidCore (the Correios field counted while SquidCore was off)
 *   and each time Correios Automático comes back (or is installed) after being off (the SquidCore NCM written
 *   meanwhile never reached its meta). A product without the SquidCore NCM adopts a valid Correios one; a Correios NCM
 *   changed outside SquidCore (or, before this version, the one that went on the NF-e) is adopted; otherwise the
 *   SquidCore NCM is copied to Correios, so its labels and international shipments have it;
 * - when one of the two NCM metas is changed outside SquidCore (a CSV import, the REST API, the block editor), the
 *   same rule runs at the end of the request (with Correios Automático off, the SquidCore NCM wins).
 *
 * While Correios Automático is off, the one-time copy waits: the import then still sees the NCM left by Correios as
 * an old, hidden one. An adoption that changes the NCM on the invoice fires ADOPTED_ACTION, which the catalog sync
 * turns into a push of the product to SquidCore.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class CorreiosNcmCopy {

	public const HOOK = 'infixs_squidcore_ncm_copy_correios';

	/**
	 * Progress: {cursor: int, copied: int} while copying, DONE when finished with Correios Automático active, WAITING
	 * while Correios Automático is off (it starts over when it is back), missing before it starts. Autoloaded: the
	 * admin reads it on every page.
	 */
	public const OPTION = 'infixs_squidcore_ncm_copy';

	public const DONE = 'done';

	public const WAITING = 'waiting';

	/**
	 * Fired with the product id when an adopted Correios Automático NCM changed the NCM on the invoice of a product,
	 * outside a product save (which pushes the product by itself). The do_action() call spells it out, as every hook
	 * the plugin fires.
	 */
	public const ADOPTED_ACTION = 'infixs_squidcore_product_ncm_adopted';

	public const BATCH = 500;

	/**
	 * Seconds between two runs of the job.
	 */
	private const NEXT_DELAY = 5;

	/**
	 * Work of one run of the job: seconds and share of the memory limit.
	 */
	private const TIME_BUDGET = 20;

	private const MEMORY_SHARE = 0.5;

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	/**
	 * @var ProductNcmStore
	 */
	protected $store;

	/**
	 * @var CorreiosAutomatico
	 */
	protected $correios;

	/**
	 * Products whose NCM metas changed in this request, put in step at its end.
	 *
	 * @var array<int, true>
	 */
	private $changed = [];

	public function __construct( BackgroundQueue $queue, ProductNcmStore $store, CorreiosAutomatico $correios ) {
		$this->queue = $queue;
		$this->store = $store;
		$this->correios = $correios;
	}

	/**
	 * The job handler and the meta watch must be registered on every request (front, admin, cron).
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'handle' ] );
		add_action( 'admin_init', [ $this, 'maybeSchedule' ] );
		add_action( 'added_post_meta', [ $this, 'onMetaChange' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'onMetaChange' ], 10, 3 );
	}

	/**
	 * Runs the copy again, from the first product (on each activation of SquidCore).
	 */
	public static function restart(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Starts the copy, or starts it again when a run died before queuing the next one. While Correios Automático is
	 * off, the copy waits to start over from the first product when it is back. Once done or waiting, it costs the
	 * read of an autoloaded option.
	 */
	public function maybeSchedule(): void {
		$state = get_option( self::OPTION, null );

		if ( ! $this->correios->isActive() ) {
			if ( $state !== self::WAITING ) {
				update_option( self::OPTION, self::WAITING, true );
			}

			return;
		}

		if ( $state === self::DONE || $this->queue->isScheduled( self::HOOK, [] ) ) {
			return;
		}

		$this->queue->schedule( self::HOOK, [], time(), true );
	}

	/**
	 * Job: batch after batch for about TIME_BUDGET seconds, then the next run.
	 */
	public function handle(): void {
		if ( ! $this->correios->isActive() ) {
			return;
		}

		$budget = new JobBudget( self::TIME_BUDGET, self::MEMORY_SHARE );

		do {
			if ( ! $this->runBatch() ) {
				return;
			}

			JobBudget::releaseRuntimeCache();
		} while ( ! $budget->exhausted() );

		// Not unique: this run still counts as a job of the same hook.
		$this->queue->schedule( self::HOOK, [], time() + self::NEXT_DELAY );
	}

	/**
	 * One batch of products with a Correios or a SquidCore NCM, after the cursor.
	 *
	 * @return bool False when the copy is finished.
	 */
	public function runBatch(): bool {
		$state = FreshOption::get( self::OPTION );

		if ( $state === self::DONE ) {
			return false;
		}

		$cursor = is_array( $state ) ? max( 0, (int) ( $state['cursor'] ?? 0 ) ) : 0;
		$copied = is_array( $state ) ? max( 0, (int) ( $state['copied'] ?? 0 ) ) : 0;
		$ids = $this->candidates( $cursor );

		if ( ! $ids ) {
			update_option( self::OPTION, self::DONE, true );
			return false;
		}

		_prime_post_caches( $ids, false, true );

		foreach ( $ids as $product_id ) {
			$cursor = $product_id;

			if ( get_post_type( $product_id ) === 'product' && $this->reconcile( $product_id ) ) {
				++$copied;
			}
		}

		update_option( self::OPTION, [
			'cursor' => $cursor,
			'copied' => $copied,
		], true );

		return true;
	}

	/**
	 * A meta of a product changed. The two NCM metas, changed outside SquidCore, are put in step at the end of the
	 * request (by then every save of that request is done).
	 *
	 * @param int|int[] $metaId
	 * @param int       $objectId
	 * @param string    $metaKey
	 */
	public function onMetaChange( $metaId, $objectId, $metaKey ): void {
		if ( ! in_array( $metaKey, [ ProductNcmStore::META_KEY, ProductNcmStore::CORREIOS_META_KEY ], true ) ) {
			return;
		}

		$product_id = (int) $objectId;

		if ( $this->store->isSynced( $product_id ) ) {
			return;
		}

		if ( ! $this->changed ) {
			add_action( 'shutdown', [ $this, 'flush' ] );
		}

		$this->changed[ $product_id ] = true;
	}

	/**
	 * End of the request: the products changed outside SquidCore are put in step.
	 */
	public function flush(): void {
		$ids = array_keys( $this->changed );
		$this->changed = [];

		foreach ( $ids as $product_id ) {
			if ( ! $this->store->isSynced( $product_id ) && get_post_type( $product_id ) === 'product' ) {
				$this->reconcile( $product_id );
			}
		}
	}

	/**
	 * Puts the metas of a product in step; when that changed the NCM on the invoice (a Correios NCM adopted over
	 * another SquidCore one), says so with ADOPTED_ACTION.
	 *
	 * @return bool Whether the SquidCore NCM changed.
	 */
	private function reconcile( int $productId ): bool {
		$before = $this->store->stateOf( $productId )->value();

		if ( ! $this->store->reconcile( $productId ) ) {
			return false;
		}

		if ( $this->store->stateOf( $productId )->value() !== $before ) {
			/**
			 * A Correios Automático NCM was adopted in the background and changed the NCM on the invoice of the product.
			 *
			 * @since 1.3.0
			 *
			 * @param int $productId
			 */
			do_action( 'infixs_squidcore_product_ncm_adopted', $productId );
		}

		return true;
	}

	/**
	 * Products (and variations, left out by the caller) after the cursor with a Correios Automático or a SquidCore NCM,
	 * through the index of each meta key: the first BATCH ids of both lists together.
	 *
	 * @return int[]
	 */
	private function candidates( int $afterId ): array {
		$ids = array_unique( array_merge(
			$this->idsWithMeta( ProductNcmStore::CORREIOS_META_KEY, $afterId ),
			$this->idsWithMeta( ProductNcmStore::META_KEY, $afterId )
		) );
		sort( $ids );

		return array_slice( $ids, 0, self::BATCH );
	}

	/**
	 * The first BATCH distinct ids after the cursor with a non-blank value of the meta key.
	 *
	 * @return int[]
	 */
	private function idsWithMeta( string $metaKey, int $afterId ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- background batch by id over the rows of one meta key.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d AND meta_value <> '' ORDER BY post_id ASC LIMIT %d",
			$metaKey,
			$afterId,
			self::BATCH
		) );

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}
}
