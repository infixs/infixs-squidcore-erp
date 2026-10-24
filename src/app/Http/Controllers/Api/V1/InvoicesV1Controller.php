<?php

namespace Infixs\SquidCore\Http\Controllers\Api\V1;

use Infixs\SquidCore\AvelPress\Routing\Controller;
use Infixs\SquidCore\AvelPress\Utils\Sanitizer;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoicePresenter;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;

defined( 'ABSPATH' ) || exit;

/**
 * Invoices (NF-e) page of the dashboard: the SquidCore list and the orders waiting for the store owner.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoicesV1Controller extends Controller {

	private const STATUSES = [ 'DRAFT', 'PROCESSING', 'AUTHORIZED', 'ERROR', 'DENIED', 'CANCELLED' ];

	private const MAX_PER_PAGE = 100;

	private const PENDING_LIMIT = 50;

	public function index( \WP_REST_Request $request ) {
		$status = strtoupper( Sanitizer::string( $request->get_param( 'status' ) ) );
		// The dashboard table names the page `p`, the key it also keeps in the admin URL, where `page` is the screen.
		$page = $request->get_param( 'p' ) ?? $request->get_param( 'page' );

		$result = $this->service()->listRemote( [
			'page' => max( 1, Sanitizer::integer( $page, 1 ) ),
			'perPage' => min( self::MAX_PER_PAGE, max( 1, Sanitizer::integer( $request->get_param( 'perPage' ), 20 ) ) ),
			'status' => in_array( $status, self::STATUSES, true ) ? $status : '',
			'search' => Sanitizer::string( $request->get_param( 'search' ) ),
		] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rows = isset( $result['data'] ) && is_array( $result['data'] ) ? array_filter( $result['data'], 'is_array' ) : [];

		return [
			'data' => array_values( array_map( [ $this->presenter(), 'presentRemote' ], $rows ) ),
			'total' => (int) ( $result['total'] ?? 0 ),
			'currentPage' => (int) ( $result['currentPage'] ?? 1 ),
			'perPage' => (int) ( $result['perPage'] ?? 20 ),
			'lastPage' => (int) ( $result['lastPage'] ?? 1 ),
		];
	}

	public function pending() {
		$service = $this->service();
		$presenter = $this->presenter();

		return [
			'data' => array_map( function ( \WC_Order $order ) use ( $service, $presenter ) {
				return $presenter->present( $order, $service->find( $order ) );
			}, $service->ordersNeedingAction( self::PENDING_LIMIT ) ),
		];
	}

	/**
	 * Resolved with the plugin app id: with several AvelPress plugins active, constructor injection picks the wrong app.
	 */
	private function service(): InvoiceService {
		return \infixs_squidcore_app( InvoiceService::class );
	}

	private function presenter(): InvoicePresenter {
		return \infixs_squidcore_app( InvoicePresenter::class );
	}
}
