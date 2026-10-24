<?php

namespace Infixs\SquidCore\Admin;

use Infixs\SquidCore\Services\OAuthConnectService;

defined( 'ABSPATH' ) || exit;

/**
 * Return of "Conectar com SquidCore" (admin-post): finishes the authorization and sends the browser to the connection
 * settings, which show the outcome.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class OAuthCallback {

	/**
	 * Query argument of the settings screen with the outcome (OAuthConnectService::RESULT_*).
	 */
	public const RESULT_QUERY_ARG = 'squidcore_oauth';

	/**
	 * @var OAuthConnectService
	 */
	protected $oauth;

	public function __construct( OAuthConnectService $oauth ) {
		$this->oauth = $oauth;
	}

	public function register(): void {
		add_action( 'admin_post_' . OAuthConnectService::CALLBACK_ACTION, [ $this, 'handle' ] );
		add_action( 'admin_post_nopriv_' . OAuthConnectService::CALLBACK_ACTION, [ $this, 'requireLogin' ] );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this store to SquidCore.', 'infixs-squidcore-erp' ), '', [ 'response' => 403 ] );
		}

		/*
		 * SquidCore sends the browser back without a nonce: the OAuth state stands for it. It is random, kept for the
		 * user who started the connection only, and used once.
		 */
		$query = [];

		foreach ( [ 'state', 'code', 'error', 'iss' ] as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked through the OAuth state, see above.
			$query[ $name ] = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';
		}

		$result = $this->oauth->complete( $query, get_current_user_id() );

		wp_safe_redirect( $this->settingsUrl( $result ) );
		exit;
	}

	/**
	 * The session ended while the store owner was in SquidCore: log in and come back to finish.
	 */
	public function requireLogin(): void {
		auth_redirect();
	}

	public static function settingsUrl( string $result ): string {
		return add_query_arg( [
			'page' => 'infixs-squidcore',
			'path' => '/settings/connection',
			self::RESULT_QUERY_ARG => $result,
		], admin_url( 'admin.php' ) );
	}
}
