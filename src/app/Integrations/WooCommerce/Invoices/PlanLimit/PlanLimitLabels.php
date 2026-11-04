<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\PlanLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Texts about the invoice limit of the SquidCore plan shared by the WordPress notice, the order box and the order
 * notes. The admin app has the same sentences in its translations.
 *
 * @package Infixs\SquidCore
 * @since   1.5.0
 */
class PlanLimitLabels {

	/**
	 * "The invoice limit of your plan was reached: 3 orders are without an invoice."
	 */
	public static function notice( int $orders ): string {
		return sprintf(
			/* translators: %s: number of orders. */
			_n(
				'The invoice limit of your plan was reached: %s order is without an invoice.',
				'The invoice limit of your plan was reached: %s orders are without an invoice.',
				$orders,
				'infixs-squidcore-erp'
			),
			number_format_i18n( $orders )
		);
	}

	/**
	 * What to do next, while no re-emission is on its way.
	 */
	public static function nextStep(): string {
		return __( 'After changing your plan, issue the pending invoices all at once.', 'infixs-squidcore-erp' );
	}

	/**
	 * Where the re-emission is, or why it stopped; blank when there is nothing to tell.
	 *
	 * @param array $retry PlanLimitRetry::status().
	 */
	public static function retry( array $retry ): string {
		if ( $retry['status'] === PlanLimitRetry::RUNNING ) {
			return sprintf(
				/* translators: 1: invoices gone through, 2: invoices to issue. */
				__( 'Issuing the pending invoices in the background: %1$s of %2$s.', 'infixs-squidcore-erp' ),
				number_format_i18n( (int) $retry['done'] ),
				number_format_i18n( (int) $retry['total'] )
			);
		}

		if ( $retry['status'] !== PlanLimitRetry::STOPPED ) {
			return '';
		}

		switch ( $retry['stoppedBy'] ) {
			case PlanLimitRetry::STOPPED_PLAN_LIMIT:
				return __( 'The plan limit is still reached, so the emission paused. Once you change your plan, try again.', 'infixs-squidcore-erp' );
			case PlanLimitRetry::STOPPED_ACCOUNT:
				return __( 'The emission paused because SquidCore refused the invoices of your account. Check your subscription and your plan in SquidCore, then try again.', 'infixs-squidcore-erp' );
			default:
				return __( 'The emission paused because SquidCore did not answer. Try again in a few minutes.', 'infixs-squidcore-erp' );
		}
	}

	/**
	 * Why an order the plan limit refused leaves the pending invoices: its sale ended.
	 *
	 * @param string $status WooCommerce status of the order: cancelled, refunded, failed or trash.
	 */
	public static function saleEnded( string $status ): string {
		switch ( $status ) {
			case 'cancelled':
				return __( 'The invoice pending for the plan limit will not be issued: the order was cancelled.', 'infixs-squidcore-erp' );
			case 'refunded':
				return __( 'The invoice pending for the plan limit will not be issued: the order was refunded.', 'infixs-squidcore-erp' );
			case 'trash':
				return __( 'The invoice pending for the plan limit will not be issued: the order was moved to the trash.', 'infixs-squidcore-erp' );
			default:
				return __( 'The invoice pending for the plan limit will not be issued: the payment of the order failed.', 'infixs-squidcore-erp' );
		}
	}

	public static function seePlans(): string {
		return __( 'See plans in SquidCore', 'infixs-squidcore-erp' );
	}

	public static function issuePending(): string {
		return __( 'Issue the pending invoices', 'infixs-squidcore-erp' );
	}

	/**
	 * Under the message of an order whose invoice failed for the plan limit.
	 */
	public static function orderNextStep(): string {
		return __( 'After changing your plan, click "Try again".', 'infixs-squidcore-erp' );
	}

	/**
	 * Link to the plans for an order note (WooCommerce shows the notes through wp_kses_post()).
	 */
	public static function plansLink( string $url ): string {
		return sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $url ), esc_html( self::seePlans() ) );
	}
}
