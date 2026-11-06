<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCoverage;

defined( 'ABSPATH' ) || exit;

/**
 * Products the AI filling goes through: the ones without a valid NCM by the rule of the count of products without NCM
 * (NcmCoverage::sql(): published and private, grouped and external left out, the SquidCore NCM or else the Correios
 * Automático one), optionally only among the products a bulk action chose. Read by id, so a run goes on from a cursor
 * and a product that still has no NCM after its batch is not asked again in the same run; the products of a batch
 * that waits to ask the AI again for some of them are left out the same way.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class NcmAiFillTargets {

	/**
	 * @var NcmCoverage
	 */
	protected $coverage;

	public function __construct( NcmCoverage $coverage ) {
		$this->coverage = $coverage;
	}

	/**
	 * The next products without NCM after the cursor.
	 *
	 * @param int[]|null $only Products chosen; null for every product.
	 * @param int[]      $skip Products left out (already settled in this run).
	 *
	 * @return int[]
	 */
	public function next( int $afterId, int $limit, ?array $only = null, array $skip = [] ): array {
		global $wpdb;

		$in = $this->in( $only );

		if ( $in === null ) {
			return [];
		}

		$skip = NcmAiFillRun::idList( $skip );
		$not_in = $skip ? ' AND p.ID NOT IN (' . implode( ',', $skip ) . ')' : '';
		$sql = $this->coverage->sql( 'p' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the fragments come from NcmCoverage::sql(), in() and idList() (integer ids); a batch of the filling reads the products fresh.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p WHERE {$sql['where']} AND {$sql['missing']}{$in}{$not_in} AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
			$afterId,
			max( 1, $limit )
		) );
		// phpcs:enable

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * How many products without NCM there are (among the chosen ones).
	 *
	 * @param int[]|null $only Products chosen; null for every product.
	 */
	public function count( ?array $only = null ): int {
		global $wpdb;

		$in = $this->in( $only );

		if ( $in === null ) {
			return 0;
		}

		$sql = $this->coverage->sql( 'p' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see next(); asked once, when a filling starts.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$sql['where']} AND {$sql['missing']}{$in}" );
	}

	/**
	 * The condition on the chosen ids ('' for every product), null when none was chosen.
	 *
	 * @param int[]|null $only
	 */
	private function in( ?array $only ): ?string {
		if ( $only === null ) {
			return '';
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $only ), function ( $id ) {
			return $id > 0;
		} ) ) );

		return $ids ? ' AND p.ID IN (' . implode( ',', $ids ) . ')' : null;
	}
}
