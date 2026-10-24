<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

use Infixs\SquidCore\Integrations\WooCommerce\ActivityRepository;
use Infixs\SquidCore\Support\OrderScreen;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the invoice record in order metas, through the WooCommerce CRUD (works with and without HPOS).
 *
 * The state and the attention live in metas of their own so orders can be counted and listed by them.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceRepository {

	public const META_RECORD = '_infixs_squidcore_invoice';

	public const META_STATE = '_infixs_squidcore_invoice_state';

	public const META_ATTENTION = '_infixs_squidcore_invoice_attention';

	/**
	 * Query var carrying a meta query to the posts storage, which ignores `meta_query` in wc_get_orders().
	 */
	private const LEGACY_META_QUERY_VAR = 'infixs_squidcore_meta_query';

	/**
	 * @var ActivityRepository
	 */
	protected $activity;

	/**
	 * @param ActivityRepository $activity Counters of the activity chart: the authorizations and cancellations saved here
	 *                                     feed it (the failures are counted by the service, which knows when one stays).
	 */
	public function __construct( ActivityRepository $activity ) {
		$this->activity = $activity;
	}

	/**
	 * Lets wc_get_orders() filter by the invoice metas when orders are stored as posts.
	 */
	public function register(): void {
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ $this, 'mapLegacyMetaQuery' ], 10, 2 );
	}

	/**
	 * @param array $wp_query_args WP_Query args built by WooCommerce.
	 * @param array $query_vars wc_get_orders() args.
	 *
	 * @return array
	 */
	public function mapLegacyMetaQuery( $wp_query_args, $query_vars ) {
		if ( ! empty( $query_vars[ self::LEGACY_META_QUERY_VAR ] ) && is_array( $query_vars[ self::LEGACY_META_QUERY_VAR ] ) ) {
			$wp_query_args['meta_query'] = isset( $wp_query_args['meta_query'] ) && is_array( $wp_query_args['meta_query'] ) ? $wp_query_args['meta_query'] : []; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$wp_query_args['meta_query'][] = $query_vars[ self::LEGACY_META_QUERY_VAR ];
		}

		return $wp_query_args;
	}

	/**
	 * @return InvoiceRecord|null Null when the order never had an invoice record.
	 */
	public function find( \WC_Order $order ): ?InvoiceRecord {
		$data = $order->get_meta( self::META_RECORD, true );

		if ( ! is_array( $data ) || empty( $data['state'] ) ) {
			return null;
		}

		$data['attention'] = (string) $order->get_meta( self::META_ATTENTION, true );

		return new InvoiceRecord( $data );
	}

	/**
	 * Reads the record again from the database, for a request that must not act on what it loaded before another
	 * request changed the invoice. Reloads the metas of the order object itself.
	 */
	public function findFresh( \WC_Order $order ): ?InvoiceRecord {
		$order->read_meta_data( true );

		return $this->find( $order );
	}

	/**
	 * Saves only the metas, so no status or order hooks run again.
	 */
	public function save( \WC_Order $order, InvoiceRecord $record ): void {
		$data = $record->toArray();
		$previous_state = (string) $order->get_meta( self::META_STATE, true );
		$changed = $previous_state !== $record->state()
			|| (string) $order->get_meta( self::META_ATTENTION, true ) !== $record->attention();

		$order->update_meta_data( self::META_RECORD, $data );
		$order->update_meta_data( self::META_STATE, $record->state() );

		if ( $record->attention() !== '' ) {
			$order->update_meta_data( self::META_ATTENTION, $record->attention() );
		} else {
			$order->delete_meta_data( self::META_ATTENTION );
		}

		$order->save_meta_data();

		if ( $previous_state !== $record->state() ) {
			$this->countStateChange( $record->state() );
		}

		if ( ! $changed ) {
			return;
		}

		/**
		 * Fires when the state or the attention of an order invoice changes in the store.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $order_id  Order id.
		 * @param string $state     New local state (scheduled, processing, authorized, blocked...).
		 * @param string $attention Attention reason, blank when none.
		 */
		do_action( 'infixs_squidcore_invoice_state_changed', $order->get_id(), $record->state(), $record->attention() );
	}

	/**
	 * Counts, for the activity chart, an invoice that just got authorized or cancelled. Only the change into the state
	 * counts: a record saved again in the same state is the same invoice event.
	 *
	 * A state that waits for the store owner is not counted here: the service saves an interim block before pushing
	 * the products or letting the AI fill the NCM, and only it knows whether the block stays. It counts the failure
	 * where it announces it (InvoiceService::fireFailed()).
	 */
	private function countStateChange( string $state ): void {
		if ( $state === InvoiceRecord::AUTHORIZED ) {
			$this->activity->increment( ActivityRepository::INVOICES_AUTHORIZED );
			return;
		}

		if ( $state === InvoiceRecord::CANCELLED ) {
			$this->activity->increment( ActivityRepository::INVOICES_CANCELLED );
		}
	}

	/**
	 * Number of orders whose invoice is in one of the states.
	 *
	 * @param string[] $states
	 */
	public function countByStates( array $states ): int {
		return $this->count( [
			'key' => self::META_STATE,
			'value' => array_values( $states ),
			'compare' => 'IN',
		] );
	}

	/**
	 * Number of orders whose invoice is in one of the states or needs attention, each order counted once.
	 *
	 * @param string[] $states
	 */
	public function countPending( array $states ): int {
		return $this->count( $this->pendingClause( $states ) );
	}

	/**
	 * Most recent orders whose invoice is in one of the states or needs attention.
	 *
	 * @param string[] $states
	 *
	 * @return \WC_Order[]
	 */
	public function findPending( array $states, int $limit ): array {
		$orders = $this->query( $this->pendingClause( $states ), [
			'limit' => $limit,
			'orderby' => 'date',
			'order' => 'DESC',
		] );

		return array_values( array_filter( (array) $orders, function ( $order ) {
			return $order instanceof \WC_Order;
		} ) );
	}

	/**
	 * Newest orders created since a moment whose invoice is authorized, in one of the order statuses, leaving out some
	 * orders and, when a meta key is given, the orders that already have it.
	 *
	 * @param int      $since       Unix time of the oldest order creation.
	 * @param string[] $statuses    Order statuses (with the wc- prefix).
	 * @param int[]    $exclude     Order ids left out.
	 * @param string   $withoutMeta Meta key the orders must not have; blank for any.
	 *
	 * @return \WC_Order[]
	 */
	public function findAuthorizedSince( int $since, array $statuses, int $limit, array $exclude = [], string $withoutMeta = '' ): array {
		$clause = [
			'key' => self::META_STATE,
			'value' => InvoiceRecord::AUTHORIZED,
		];

		if ( $withoutMeta !== '' ) {
			$clause = [
				'relation' => 'AND',
				$clause,
				[
					'key' => $withoutMeta,
					'compare' => 'NOT EXISTS',
				],
			];
		}

		$args = [
			'limit' => $limit,
			'orderby' => 'date',
			'order' => 'DESC',
			'status' => array_values( $statuses ),
			'date_created' => '>=' . $since,
		];

		if ( $exclude ) {
			$args['exclude'] = array_values( array_map( 'intval', $exclude ) );
		}

		return array_values( array_filter( (array) $this->query( $clause, $args ), function ( $order ) {
			return $order instanceof \WC_Order;
		} ) );
	}

	/**
	 * @param string[] $states
	 */
	private function pendingClause( array $states ): array {
		return [
			'relation' => 'OR',
			[
				'key' => self::META_STATE,
				'value' => array_values( $states ),
				'compare' => 'IN',
			],
			$this->attentionClause(),
		];
	}

	private function attentionClause(): array {
		return [
			'key' => self::META_ATTENTION,
			'value' => '',
			'compare' => '!=',
		];
	}

	private function count( array $clause ): int {
		$result = $this->query( $clause, [
			'limit' => 1,
			'paginate' => true,
			'return' => 'ids',
		] );

		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * @param array $metaQuery
	 * @param array $args Other wc_get_orders() args.
	 *
	 * @return mixed What wc_get_orders() returns.
	 */
	private function query( array $metaQuery, array $args ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		// The order tables read `meta_query` as a list of clauses, like WP_Meta_Query; the posts storage gets the
		// clause through mapLegacyMetaQuery(), which appends it to the list.
		$meta = OrderScreen::usesOrderTables()
			? [ 'meta_query' => [ $metaQuery ] ] // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			: [ self::LEGACY_META_QUERY_VAR => $metaQuery ];

		return wc_get_orders( array_merge( [
			'type' => 'shop_order',
		], $args, $meta ) );
	}
}
