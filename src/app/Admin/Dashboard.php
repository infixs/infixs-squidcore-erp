<?php

namespace Infixs\SquidCore\Admin;

use Infixs\SquidCore\Support\SquidCoreUrls;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard Class
 * 
 * Loads the admin app and handles the deactivation feedback.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class Dashboard {
	public function __construct() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'admin_footer', [ $this, 'unistall_html_modal' ] );
		add_action( 'wp_ajax_infixs_squidcore_deactivate', [ $this, 'submit_deactivate_feedback' ] );
	}

	/**
	 * Enqueue scripts.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts( $hook ) {
		if ( ! $this->is_dashboard_page() && 'plugins.php' !== $hook ) {
			return;
		}

		$script_data = [];

		if ( 'plugins.php' === $hook ) {
			$script_data['unistallNonce'] = wp_create_nonce( 'infixs-squidcore-uninstall' );
		}

		$this->load_dashboard_scripts( $script_data );
	}

	public function is_dashboard_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which page is open.
		return isset( $_GET['page'] ) && 'infixs-squidcore' === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	public function load_dashboard_scripts( $params = [] ) {
		wp_enqueue_script( 'infixs-squidcore-admin',
			\INFIXS_SQUIDCORE_PLUGIN_URL . 'assets/dashboard/js/main.js',
			[],
			filemtime( \INFIXS_SQUIDCORE_PLUGIN_PATH . 'assets/dashboard/js/main.js' ),
			true
		);

		$app = \infixs_squidcore_app();

		$default_params = [
			'adminUrl' => admin_url(),
			'adminPath' => wp_parse_url( admin_url(), PHP_URL_PATH ),
			'pluginBasename' => \INFIXS_SQUIDCORE_BASE_NAME,
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'restUrl' => get_rest_url( null, 'infixs-squidcore/v1/' ),
			'appUrl' => $app->make( SquidCoreUrls::class )->appUrl(),
			'supportUrl' => $this->getSupportUrl( $app->config()->string( 'app.support_whatsapp', '' ) ),
			'translations' => $app->getTranslations(),
			'plugins' => $this->getPluginsIntegrations(),
			'locale' => $this->getLocale(),
		];

		$params = array_merge( $default_params, $params );

		$params = apply_filters( 'infixs_squidcore_dashboard_script_params', $params );

		wp_localize_script( 'infixs-squidcore-admin', 'infixsSquidCore', $params );
	}

	protected function getSupportUrl( string $phone ): string {
		if ( $phone === '' ) {
			return '';
		}

		return add_query_arg( [
			'phone' => rawurlencode( $phone ),
			'text' => rawurlencode( __( 'Hi! I need help with the SquidCore plugin for WordPress.', 'infixs-squidcore-erp' ) ),
		], 'https://api.whatsapp.com/send/' );
	}

	public function getLocale() {
		$locale = get_locale();
		if ( ! is_string( $locale ) || empty( $locale ) ) {
			$locale = get_option( 'WPLANG' );
		}

		if ( ! is_string( $locale ) || empty( $locale ) ) {
			return 'en-US';
		}

		return $this->normalizeLocale( $locale );
	}

	protected function normalizeLocale( $locale ) {
		$locale = str_replace( '_', '-', $locale );
		$parts = explode( '-', $locale );

		if ( count( $parts ) >= 2 ) {
			$language = strtolower( $parts[0] );
			$country = strtoupper( $parts[1] );
			return $language . '-' . $country;
		}

		return strtolower( $locale );
	}

	protected function getPluginsIntegrations() {
		$plugins = [];

		if ( class_exists( 'WooCommerce' ) ) {
			array_push( $plugins, 'woocommerce' );
		}

		return $plugins;
	}

	/**
	 * Add admin unistall template.
	 */
	public function unistall_html_modal() {
		global $pagenow;

		if ( 'plugins.php' === $pagenow ) {
			include_once \INFIXS_SQUIDCORE_PLUGIN_PATH . 'src/resources/views/html-uninstall.php';
		}
	}

	public function submit_deactivate_feedback() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized operation.', 'infixs-squidcore-erp' ) ], 403 );
		}

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce(
			sanitize_text_field(
				wp_unslash( $_POST['nonce'] )
			),
			'infixs-squidcore-uninstall'
		) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized operation.', 'infixs-squidcore-erp' ) ] );
		}

		if ( ! isset( $_POST['reason'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Please provide a reason for deactivation.', 'infixs-squidcore-erp' ) ] );
		}

		$reason = sanitize_text_field( wp_unslash( $_POST['reason'] ) );
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		$response = wp_safe_remote_post( 'https://api.infixs.io/v1/plugin/deactivate', [
			"body" => wp_json_encode( [
				'plugin_id' => 'infixs-squidcore',
				'php_version' => phpversion(),
				'plugin_version' => \infixs_squidcore_version(),
				'site' => site_url(),
				'reason' => $reason,
				'email' => $email,
				'description' => $description,
			] ),
			'headers' => [
				'Content-Type' => 'application/json',
			]
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ] );
		}

		wp_send_json_success( [ 'message' => __( 'Feedback sent successfully.', 'infixs-squidcore-erp' ) ] );
	}
}
