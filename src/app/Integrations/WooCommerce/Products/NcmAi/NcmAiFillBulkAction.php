<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk action "Fill NCM with AI" of the WooCommerce products list: the selected products without NCM go to the AI
 * filling, in the background. A script asks first how many of them lack the NCM and confirms, telling that the AI of
 * the organization answers on the key of the store; the list then tells the filling started and, while it runs, how far
 * it got, with a link to the results in the plugin settings.
 *
 * WordPress checks the nonce of the list before running a bulk action; the capability is checked here. It is the one
 * of the plugin settings (manage_options), where the filling is followed, stopped and reviewed: who starts a filling,
 * charged on the AI key of the store, can always stop it and see what it did.
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class NcmAiFillBulkAction {

	public const ACTION = 'infixs_squidcore_ncm_ai_fill';

	/**
	 * Query argument of the products list after the action: started, or the code of why it did not start.
	 */
	public const QUERY_VAR = 'infixs_squidcore_ncm_ai';

	/**
	 * Query argument with the number of products the filling started with.
	 */
	public const COUNT_QUERY_VAR = 'infixs_squidcore_ncm_ai_count';

	public const STARTED = 'started';

	public const SCRIPT_HANDLE = 'infixs-squidcore-ncm-ai-bulk';

	/**
	 * Where the results are reviewed.
	 */
	public const RESULTS_PATH = 'admin.php?page=infixs-squidcore&path=/settings/invoices';

	private const SCREEN_ID = 'edit-product';

	public const CAPABILITY = 'manage_options';

	/**
	 * Why a filling did not start => notice.
	 */
	private const REFUSALS = [ NcmAiErrors::NOT_CONNECTED, NcmAiErrors::UNAVAILABLE, NcmAiErrors::UNSUPPORTED, NcmAiErrors::BUSY, NcmAiErrors::NOTHING ];

	/**
	 * @var NcmAiFillService
	 */
	protected $service;

	public function __construct( NcmAiFillService $service ) {
		$this->service = $service;
	}

	public function register(): void {
		add_filter( 'bulk_actions-' . self::SCREEN_ID, [ $this, 'addAction' ] );
		add_filter( 'handle_bulk_actions-' . self::SCREEN_ID, [ $this, 'handle' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'renderNotice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * @param array $actions Bulk actions of the products list.
	 *
	 * @return array
	 */
	public function addAction( $actions ) {
		$actions = is_array( $actions ) ? $actions : [];

		if ( current_user_can( self::CAPABILITY ) && $this->service->canStart() ) {
			$actions[ self::ACTION ] = __( 'Fill NCM with AI', 'infixs-squidcore-erp' );
		}

		return $actions;
	}

	/**
	 * Starts the filling of the selected products without NCM and goes back to the list, which tells what happened.
	 *
	 * @param string $redirect Address WordPress goes to after the action.
	 * @param string $action   Bulk action.
	 * @param int[]  $ids      Selected product ids.
	 *
	 * @return string
	 */
	public function handle( $redirect, $action, $ids ) {
		if ( $action !== self::ACTION || ! current_user_can( self::CAPABILITY ) ) {
			return $redirect;
		}

		$redirect = remove_query_arg( [ self::QUERY_VAR, self::COUNT_QUERY_VAR ], (string) $redirect );
		$run = $this->service->start( is_array( $ids ) ? $ids : [], wp_get_current_user() );

		if ( is_wp_error( $run ) ) {
			return add_query_arg( self::QUERY_VAR, $run->get_error_code(), $redirect );
		}

		return add_query_arg( [
			self::QUERY_VAR => self::STARTED,
			self::COUNT_QUERY_VAR => (int) $run['total'],
		], $redirect );
	}

	/**
	 * Back on the products list: the filling started (or why not), and while a filling runs, how far it got.
	 */
	public function renderNotice(): void {
		if ( ! $this->isListScreen() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads the outcome of a past action.
		$outcome = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		$results = sprintf( ' <a href="%s">%s</a>', esc_url( admin_url( self::RESULTS_PATH ) ), esc_html__( 'Follow and review', 'infixs-squidcore-erp' ) );

		if ( $outcome === self::STARTED ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads the outcome of a past action.
			$count = isset( $_GET[ self::COUNT_QUERY_VAR ] ) ? absint( wp_unslash( $_GET[ self::COUNT_QUERY_VAR ] ) ) : 0;

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s%s</p></div>',
				esc_html( sprintf(
					/* translators: %s: number of products. */
					_n( 'The AI is filling the NCM of %s product without NCM, in the background.', 'The AI is filling the NCM of %s products without NCM, in the background.', $count, 'infixs-squidcore-erp' ),
					number_format_i18n( $count )
				) ),
				$results // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			);

			return;
		}

		if ( in_array( $outcome, self::REFUSALS, true ) ) {
			printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( NcmAiErrors::message( $outcome ) ) );

			return;
		}

		$run = $this->service->status()['run'];

		if ( $run !== null && $run['status'] === NcmAiFillRun::RUNNING ) {
			printf(
				'<div class="notice notice-info"><p>%s%s</p></div>',
				esc_html( sprintf(
					/* translators: 1: products done, 2: products of the filling. */
					__( 'Filling the NCM with AI: %1$s of %2$s products.', 'infixs-squidcore-erp' ),
					number_format_i18n( $run['processed'] ),
					number_format_i18n( $run['total'] )
				) ),
				$results // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			);
		}
	}

	/**
	 * The confirmation of the bulk action, on the products list only, while the action is offered.
	 */
	public function enqueue(): void {
		if ( ! $this->isListScreen() || ! current_user_can( self::CAPABILITY ) || ! $this->service->canStart() ) {
			return;
		}

		$path = 'assets/admin/js/ncm-ai-bulk.js';

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			\INFIXS_SQUIDCORE_PLUGIN_URL . $path,
			[ 'wp-api-fetch' ],
			(string) filemtime( \INFIXS_SQUIDCORE_PLUGIN_PATH . $path ),
			true
		);

		wp_localize_script( self::SCRIPT_HANDLE, 'infixsSquidCoreNcmAiBulk', [
			'action' => self::ACTION,
			'previewPath' => '/infixs-squidcore/v1/ncm-ai-fill/preview',
			'i18n' => [
				/* translators: %s: number of products. */
				'confirmOne' => __( 'The AI connected to your organization in SquidCore (OpenAI or Claude) will suggest the NCM of %s selected product without NCM. The queries are charged on the API key of your store. Each NCM it finds is saved on the product: review them afterwards. Continue?', 'infixs-squidcore-erp' ),
				/* translators: %s: number of products. */
				'confirmMany' => __( 'The AI connected to your organization in SquidCore (OpenAI or Claude) will suggest the NCM of %s selected products without NCM. The queries are charged on the API key of your store. Each NCM it finds is saved on the product: review them afterwards. Continue?', 'infixs-squidcore-erp' ),
				'none' => __( 'None of the selected products is without NCM.', 'infixs-squidcore-erp' ),
				'noSelection' => __( 'Select the products whose NCM the AI should fill.', 'infixs-squidcore-erp' ),
				'error' => __( 'Could not check the selected products. Try again.', 'infixs-squidcore-erp' ),
			],
		] );
	}

	private function isListScreen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen !== null && $screen->id === self::SCREEN_ID;
	}
}
