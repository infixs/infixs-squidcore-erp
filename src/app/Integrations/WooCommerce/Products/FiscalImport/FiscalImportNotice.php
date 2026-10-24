<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Support\OrderScreen;

defined( 'ABSPATH' ) || exit;

/**
 * The notices of the import on a few WordPress screens (Dashboard, Plugins, Products and the order list), for the
 * administrators. Inside the SquidCore app, which hides the WordPress notices, the app shows them itself.
 *
 * The X of a notice dismisses it through the REST API (notices.js); "Remind me" is a plain link, with a nonce, that
 * works without JavaScript.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportNotice {

	public const SCRIPT_HANDLE = 'infixs-squidcore-notices';

	/**
	 * admin-post action and nonce action of the "Remind me" links.
	 */
	public const DISMISS_ACTION = 'infixs_squidcore_dismiss_notice';

	public const IMPORT_PATH = 'admin.php?page=infixs-squidcore&path=/settings/import';

	private const SCREENS = [ 'dashboard', 'plugins', 'edit-product' ];

	private const SCRIPT_PATH = 'assets/admin/js/notices.js';

	/**
	 * @var FiscalImportNoticeBoard
	 */
	protected $board;

	/**
	 * Notices due on this page (enqueue and render), read once: [screen and user, notices].
	 *
	 * @var array|null
	 */
	private $due = null;

	public function __construct( FiscalImportNoticeBoard $board ) {
		$this->board = $board;
	}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_post_' . self::DISMISS_ACTION, [ $this, 'handleDismiss' ] );
	}

	public function render(): void {
		foreach ( $this->due() as $notice ) {
			echo $this->html( $notice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		}
	}

	public function enqueue(): void {
		if ( ! $this->due() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			\INFIXS_SQUIDCORE_PLUGIN_URL . self::SCRIPT_PATH,
			[ 'wp-api-fetch' ],
			(string) filemtime( \INFIXS_SQUIDCORE_PLUGIN_PATH . self::SCRIPT_PATH ),
			true
		);

		wp_localize_script( self::SCRIPT_HANDLE, 'infixsSquidCoreNotices', [
			'restPath' => '/infixs-squidcore/v1/notices/',
		] );
	}

	/**
	 * "Remind me in N days": dismisses the notice and goes back to the page.
	 */
	public function handleDismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'infixs-squidcore-erp' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::DISMISS_ACTION );

		$id = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		$mode = in_array( $mode, FiscalImportNoticeBoard::MODES, true ) ? $mode : FiscalImportNoticeBoard::MODE_SNOOZE;

		if ( $this->board->dismiss( get_current_user_id(), $id, $mode ) === null ) {
			wp_die( esc_html__( 'Unknown notice.', 'infixs-squidcore-erp' ), '', [ 'response' => 400 ] );
		}

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * @return array<int, array{id: string, kind: string, count: int|null, actionUrl: string|null}>
	 */
	private function due(): array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$key = ( $screen ? $screen->id : '' ) . '|' . get_current_user_id();

		if ( $this->due === null || $this->due[0] !== $key ) {
			$this->due = [ $key, current_user_can( 'manage_options' ) && $this->isRelevantScreen() ? $this->board->due( get_current_user_id() ) : [] ];
		}

		return $this->due[1];
	}

	private function isRelevantScreen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen !== null && ( in_array( $screen->id, self::SCREENS, true ) || OrderScreen::isOrderListScreen() );
	}

	/**
	 * @param array{id: string, kind: string, count: int|null, actionUrl: string|null} $notice
	 */
	private function html( array $notice ): string {
		$import_url = admin_url( self::IMPORT_PATH );

		switch ( $notice['id'] ) {
			case FiscalImportNoticeBoard::IMPORT:
				$title = __( 'Make the most of the NCM codes you already saved in WebmaniaBR', 'infixs-squidcore-erp' );
				$text = $notice['count'] === null
					? __( 'We found NCM codes of your products in WebmaniaBR. Bring them to SquidCore in a few clicks.', 'infixs-squidcore-erp' )
					: sprintf(
						/* translators: %s: number of products. */
						_n(
							'%s product can get this NCM in a few clicks, so you issue invoices without typing it again.',
							'%s products can get these NCM codes in a few clicks, so you issue invoices without typing them again.',
							$notice['count'],
							'infixs-squidcore-erp'
						),
						number_format_i18n( $notice['count'] )
					);
				$primary = [ __( 'See what can be imported', 'infixs-squidcore-erp' ), $import_url ];
				$secondary = [ __( 'Remind me in 7 days', 'infixs-squidcore-erp' ), $this->snoozeUrl( $notice['id'] ) ];
				break;

			case FiscalImportNoticeBoard::DOUBLE_EMISSION:
				$title = __( 'One invoice per order', 'infixs-squidcore-erp' );
				$text = __( 'To keep one invoice per order, leave the automatic emission on in SquidCore only.', 'infixs-squidcore-erp' );
				$primary = [ __( 'Open WebmaniaBR settings', 'infixs-squidcore-erp' ), (string) $notice['actionUrl'] ];
				$secondary = [ __( 'Remind me in 30 days', 'infixs-squidcore-erp' ), $this->snoozeUrl( $notice['id'] ) ];
				break;

			default:
				$title = '';
				$text = sprintf(
					/* translators: %s: number of products. */
					_n( 'Import finished: %s product now has an NCM.', 'Import finished: %s products now have an NCM.', (int) $notice['count'], 'infixs-squidcore-erp' ),
					number_format_i18n( (int) $notice['count'] )
				);
				$primary = [ __( 'See summary', 'infixs-squidcore-erp' ), $import_url ];
				$secondary = null;
		}

		$buttons = sprintf( '<a class="button button-primary" href="%s">%s</a>', esc_url( $primary[1] ), esc_html( $primary[0] ) );

		if ( $secondary !== null ) {
			$buttons .= sprintf( ' <a class="button" href="%s">%s</a>', esc_url( $secondary[1] ), esc_html( $secondary[0] ) );
		}

		return sprintf(
			'<div class="notice notice-%s is-dismissible infixs-squidcore-notice" data-isq-notice="%s">%s<p>%s</p><p>%s</p></div>',
			esc_attr( $notice['kind'] ),
			esc_attr( $notice['id'] ),
			$title !== '' ? '<p><strong>' . esc_html( $title ) . '</strong></p>' : '',
			esc_html( $text ),
			$buttons
		);
	}

	private function snoozeUrl( string $id ): string {
		return wp_nonce_url(
			add_query_arg( [
				'action' => self::DISMISS_ACTION,
				'notice' => $id,
				'mode' => FiscalImportNoticeBoard::MODE_SNOOZE,
			], admin_url( 'admin-post.php' ) ),
			self::DISMISS_ACTION
		);
	}
}
