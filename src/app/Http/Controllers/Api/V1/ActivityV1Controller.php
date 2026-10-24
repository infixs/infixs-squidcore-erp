<?php

namespace Infixs\SquidCore\Http\Controllers\Api\V1;

use Infixs\SquidCore\AvelPress\Routing\Controller;
use Infixs\SquidCore\AvelPress\Utils\Sanitizer;
use Infixs\SquidCore\Integrations\WooCommerce\ActivityRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Activity chart of the dashboard: pushes and invoice events per day over the chosen period. Local data only.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class ActivityV1Controller extends Controller {

	/**
	 * Periods the dashboard offers, in days.
	 */
	private const PERIODS = [ 7, 14, 30 ];

	private const DEFAULT_PERIOD = 14;

	public function index( \WP_REST_Request $request ) {
		$days = Sanitizer::integer( $request->get_param( 'days' ), self::DEFAULT_PERIOD );

		if ( ! in_array( $days, self::PERIODS, true ) ) {
			return new \WP_Error( 'invalid_period', __( 'The period must be 7, 14 or 30 days.', 'infixs-squidcore-erp' ), [ 'status' => 400 ] );
		}

		$activity = $this->activity();
		$series = $activity->days( $days );

		return [
			'data' => [
				'days' => $days,
				'timezone' => $activity->timezone(),
				'series' => $series,
				'totals' => $activity->totals( $series ),
			],
		];
	}

	/**
	 * Resolved with the plugin app id: with several AvelPress plugins active, constructor injection picks the wrong app.
	 */
	private function activity(): ActivityRepository {
		return \infixs_squidcore_app( ActivityRepository::class );
	}
}
