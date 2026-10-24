<?php

namespace Infixs\SquidCore\Admin;

use Infixs\SquidCore\AvelPress\Admin\Menu\MenuBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Menu Class
 * 
 * Configure the admin menus
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class Menu extends MenuBuilder {

	public function register() {

		$menu = $this
			->add( 'infixs-squidcore', __( 'SquidCore', 'infixs-squidcore-erp' ) )
			->icon( 'data:image/svg+xml;base64,' . base64_encode( '<svg width="4039" height="4422" viewBox="0 0 4039 4422" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2037.53 0C2807.58 19.2751 3425.94 649.45 3426.18 1424.09L3414.78 2530.33C3400.34 2818.95 3275.39 3038.77 3072.63 3191.53C3118.28 3273.68 3152.75 3362.52 3414.78 3408.12C3676.82 3453.72 3642.64 3046.92 3939 3202.93C4235.33 3358.94 3806.65 3831.55 3494.42 3852.72C2821.81 3898.32 2696.38 3408.14 2639.38 3408.12C2582.39 3408.14 2574.57 3698.64 2639.43 3852.72C2714.88 4031.93 3037.64 4046.52 2981.43 4263.12C2916.41 4513.64 2527.45 4452.38 2308.79 4183.31C2028.23 3838.07 2176.59 3523.53 2019.36 3490.79C1862.13 3523.53 2010.49 3838.07 1729.93 4183.31C1511.28 4452.38 1122.31 4513.64 1057.3 4263.12C1001.09 4046.52 1323.84 4031.93 1399.29 3852.72C1464.16 3698.64 1456.33 3408.12 1399.33 3408.12C1342.33 3408.15 1216.91 3898.32 544.303 3852.72C232.075 3831.55 -196.612 3358.94 99.7264 3202.93C396.087 3046.92 361.91 3453.72 623.945 3408.12C885.976 3362.52 920.445 3273.68 966.097 3191.53C763.337 3038.77 638.387 2818.95 623.945 2530.33L612.544 1424.09C612.782 649.458 1231.12 19.2879 2001.17 0H2037.53ZM1332.42 2211.73C1143.54 2211.73 990.419 2364.85 990.419 2553.73C990.419 2742.61 1143.54 2895.73 1332.42 2895.73C1521.3 2895.73 1674.41 2742.61 1674.41 2553.73C1674.41 2364.85 1521.3 2211.73 1332.42 2211.73ZM2700.4 2211.73C2511.52 2211.73 2358.4 2364.85 2358.4 2553.73C2358.4 2742.61 2511.52 2895.73 2700.4 2895.73C2889.28 2895.73 3042.4 2742.61 3042.4 2553.73C3042.4 2364.85 2889.28 2211.73 2700.4 2211.73Z" fill="white"/></svg>' ) )
			->capability( 'manage_options' )
			->position( 68 );

		$menu->addSubmenu( __( 'Dashboard', 'infixs-squidcore-erp' ) )
			->capability( 'manage_options' )
			->path( '/dashboard' );

		$menu->addSubmenu( __( 'Synchronization', 'infixs-squidcore-erp' ) )
			->capability( 'manage_options' )
			->path( '/sync' );

		$menu->addSubmenu( __( 'Invoices', 'infixs-squidcore-erp' ) )
			->capability( 'manage_options' )
			->path( '/invoices' );

		$menu->addSubmenu( __( 'Settings', 'infixs-squidcore-erp' ) )
			->capability( 'manage_options' )
			->path( '/settings' );
	}

}