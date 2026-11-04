<?php

namespace Infixs\SquidCore\Http\Controllers\Api\V1;

use Infixs\SquidCore\AvelPress\Routing\Controller;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit\PlanLimitRetry;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit\PlanLimitStatus;

defined( 'ABSPATH' ) || exit;

/**
 * The invoice limit of the SquidCore plan in the admin app: "Issue the pending invoices" and the "Refresh" of the use
 * of the month. Both answer with the plan limit block of /status.
 *
 * @package Infixs\SquidCore
 * @since   1.5.0
 */
class PlanLimitV1Controller extends Controller {

	public function retry() {
		$started = \infixs_squidcore_app( PlanLimitRetry::class )->start();

		if ( is_wp_error( $started ) ) {
			return $started;
		}

		return new \WP_REST_Response( [ 'data' => $this->status()->current() ], 202 );
	}

	public function refreshUsage() {
		return [ 'data' => $this->status()->refreshUsage() ];
	}

	/**
	 * Resolved with the plugin app id: with several AvelPress plugins active, constructor injection picks the wrong app.
	 */
	private function status(): PlanLimitStatus {
		return \infixs_squidcore_app( PlanLimitStatus::class );
	}
}
