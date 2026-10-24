<?php

namespace Infixs\SquidCore\Http\Controllers\Api\V1;

use Infixs\SquidCore\AvelPress\Routing\Controller;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCoverageStatus;

defined( 'ABSPATH' ) || exit;

/**
 * "Refresh" of the count of products without NCM.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class NcmCoverageV1Controller extends Controller {

	public function refresh() {
		return new \WP_REST_Response( [ 'data' => \infixs_squidcore_app( NcmCoverageStatus::class )->refresh() ], 202 );
	}
}
