<?php

namespace Infixs\SquidCore\Http\Controllers\Admin;

use Infixs\SquidCore\AvelPress\Routing\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the mount point of the SquidCore admin app (wp-admin).
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class SettingsController extends Controller {

	public function index() {
		return \infixs_squidcore_app( 'view' )->make( 'dashboard' );
	}
}
