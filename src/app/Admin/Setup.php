<?php

namespace Infixs\SquidCore\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Setup
 * 
 * Global Admin Methodos for configuration and settings.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class Setup {
	public function __construct() {
		new Dashboard();
		add_filter( 'plugin_action_links_' . \INFIXS_SQUIDCORE_BASE_NAME, [ $this, 'plugin_action_links' ] );
	}


	/**
	 * Add links to plugin settings page.
	 * 
	 * @since 1.0.0
	 * 
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$pluginLinks = [];

		$baseUrl = esc_url( admin_url( 'admin.php?page=infixs-squidcore&path=/settings/connection' ) );

		$pluginLinks[] = sprintf( '<a href="%s">%s</a>', $baseUrl, esc_html__( 'Settings', 'infixs-squidcore-erp' ) );

		return array_merge( $pluginLinks, $links );
	}
}