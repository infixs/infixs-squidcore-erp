<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products;

defined( 'ABSPATH' ) || exit;

/**
 * "Without NCM (n)" on the WooCommerce products list: a view link with the number of the last NCM count, and the
 * filter that lists exactly the products that count left without a valid NCM.
 *
 * The link shows the cached number, so the list never counts. The filter adds the same condition as the count
 * (NcmCoverage::sql()) to the list query, only when asked for: subqueries over the rows of the two NCM keys, no join
 * with the metas, so the list does not read every meta of every product.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class MissingNcmProductsView {

	/**
	 * Query argument of the list, and query var of the filtered query.
	 */
	public const QUERY_VAR = 'infixs_squidcore_ncm';

	public const MISSING = 'missing';

	/**
	 * Key of the view among the links of the list.
	 */
	private const VIEW = 'infixs_squidcore_ncm_missing';

	/**
	 * @var NcmCoverage
	 */
	protected $coverage;

	public function __construct( NcmCoverage $coverage ) {
		$this->coverage = $coverage;
	}

	public function register(): void {
		add_filter( 'views_edit-product', [ $this, 'addView' ] );
		add_action( 'pre_get_posts', [ $this, 'filterList' ] );
		add_filter( 'posts_where', [ $this, 'where' ], 10, 2 );
	}

	/**
	 * The products list showing only the products without NCM.
	 */
	public function url(): string {
		return admin_url( 'edit.php?post_type=product&' . self::QUERY_VAR . '=' . self::MISSING );
	}

	/**
	 * Adds "Without NCM (n)" after the status links, while the last count found products without NCM. The first visit
	 * of a store that was never counted queues its first count.
	 *
	 * @param array $views Links of the list.
	 *
	 * @return array
	 */
	public function addView( $views ) {
		$views = is_array( $views ) ? $views : [];
		$this->coverage->ensureScheduled();
		$count = $this->coverage->withoutNcm();

		if ( $count <= 0 ) {
			return $views;
		}

		$active = $this->isRequested();

		if ( $active ) {
			foreach ( $views as $key => $view ) {
				$views[ $key ] = str_replace( [ ' class="current"', ' aria-current="page"' ], '', (string) $view );
			}
		}

		$label = sprintf(
			/* translators: %s: number of products without NCM. */
			__( 'Without NCM <span class="count">(%s)</span>', 'infixs-squidcore-erp' ),
			number_format_i18n( $count )
		);

		$views[ self::VIEW ] = sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( $this->url() ),
			$active ? ' class="current" aria-current="page"' : '',
			wp_kses( $label, [ 'span' => [ 'class' => true ] ] )
		);

		return $views;
	}

	/**
	 * Marks the main query of the products list when the filter is asked for.
	 *
	 * @param \WP_Query $query
	 */
	public function filterList( $query ): void {
		global $pagenow;

		if ( ! $query instanceof \WP_Query || ! is_admin() || ! $query->is_main_query() || $pagenow !== 'edit.php' ) {
			return;
		}

		if ( $query->get( 'post_type' ) === 'product' && $this->isRequested() ) {
			$query->set( self::QUERY_VAR, self::MISSING );
		}
	}

	/**
	 * @param string    $where
	 * @param \WP_Query $query
	 *
	 * @return string
	 */
	public function where( $where, $query ) {
		if ( ! $this->isFiltered( $query ) ) {
			return $where;
		}

		global $wpdb;

		$sql = $this->coverage->sql( $wpdb->posts );

		return $where . ' AND ' . $sql['where'] . ' AND ' . $sql['missing'];
	}

	/**
	 * @param mixed $query
	 */
	private function isFiltered( $query ): bool {
		return $query instanceof \WP_Query && $query->get( self::QUERY_VAR ) === self::MISSING;
	}

	/**
	 * The list was opened with the filter (a read-only view of the list, so there is no nonce).
	 */
	private function isRequested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter of the products list.
		return isset( $_GET[ self::QUERY_VAR ] ) && sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) === self::MISSING;
	}
}
