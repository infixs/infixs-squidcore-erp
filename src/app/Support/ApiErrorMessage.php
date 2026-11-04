<?php

namespace Infixs\SquidCore\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a failed SquidCore API request into a message for the store owner.
 *
 * Statuses with a known meaning get a translated message; anything else keeps the text from the API.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class ApiErrorMessage {

	/**
	 * A 403 of an invoice request: the token was generated before the invoice (NF-e) scopes.
	 */
	public const FORBIDDEN_TOKEN_SCOPE = 'token_scope';

	/**
	 * A 403 of an invoice request: the user who generated the token lacks the permission (or reaches only their own
	 * invoices, while the store works with every invoice of the organization).
	 */
	public const FORBIDDEN_USER_PERMISSION = 'user_permission';

	/**
	 * A 403 of an invoice request that may be either, since the scopes of the token are not known.
	 */
	public const FORBIDDEN_UNKNOWN = 'unknown';

	/**
	 * Code SquidCore gives every permission refusal; the missing keys come in `missingPermissions`.
	 */
	public const INSUFFICIENT_PERMISSIONS = 'INSUFFICIENT_PERMISSIONS';

	private const INVOICE_PERMISSIONS = [ 'tax_invoice.read', 'tax_invoice.write', 'tax_invoice.cancel' ];

	public static function describe( \WP_Error $error ): string {
		$oauth = self::usesOAuth( $error );

		switch ( self::status( $error ) ) {
			case 401:
				return $oauth ? self::reconnectMessage() : __( 'The access token is invalid, expired or revoked. Generate a new token in SquidCore.', 'infixs-squidcore-erp' );
			case 403:
				// Plugin tokens always carry the product, order and customer scopes: what is missing is a permission of the user.
				if ( self::code( $error ) === self::INSUFFICIENT_PERMISSIONS && self::missingPermissions( $error ) ) {
					return self::userPermissionMessage( self::missingPermissions( $error ), $oauth );
				}

				return $oauth
					? __( 'The SquidCore connection does not have permission to use the WooCommerce integration. Connect with SquidCore again and approve every permission.', 'infixs-squidcore-erp' )
					: __( 'The access token does not have permission to use the WooCommerce integration.', 'infixs-squidcore-erp' );
			case 404:
				return __( 'WooCommerce integration not found. Check that it is active in SquidCore.', 'infixs-squidcore-erp' );
		}

		if ( 'http_request_failed' === $error->get_error_code() ) {
			/* translators: %s: error returned by the HTTP request. */
			return sprintf( __( 'Could not reach the SquidCore API: %s', 'infixs-squidcore-erp' ), $error->get_error_message() );
		}

		return $error->get_error_message();
	}

	/**
	 * Message for a failed invoice (NF-e) request: the token scope, the user permission, the plan and the invoice
	 * error codes have their own texts. Messages the API writes for the store owner (blocking reasons, SEFAZ answers)
	 * are kept.
	 *
	 * @param bool|null $tokenHasInvoiceScopes Whether the saved token carries the invoice scopes, as the handshake
	 *                                         told; null when unknown.
	 */
	public static function describeInvoice( \WP_Error $error, ?bool $tokenHasInvoiceScopes = null ): string {
		$status = self::status( $error );
		$code = self::code( $error );

		$forbidden = self::forbiddenReason( $error, $tokenHasInvoiceScopes );

		if ( $forbidden !== null ) {
			return self::forbiddenMessage( $error, $forbidden );
		}

		switch ( $code ) {
			case 'PLAN_LIMIT_REACHED':
				return self::planLimitMessage( $error );
			case 'MODULE_NOT_IN_PLAN':
				return __( 'Your SquidCore plan does not include invoices (NF-e). Upgrade your plan to issue them.', 'infixs-squidcore-erp' );
			case 'ACCOUNT_SUSPENDED':
				return __( 'Your SquidCore account is suspended. Check your subscription in SquidCore.', 'infixs-squidcore-erp' );
			case 'ACCOUNT_RESTRICTED':
				return __( 'Your SquidCore account is restricted. Check your subscription in SquidCore.', 'infixs-squidcore-erp' );
			case 'WOO_ORDER_NOT_FOUND':
				return __( 'SquidCore has not received this order yet. Check the connection and try again.', 'infixs-squidcore-erp' );
			case 'NFE_NOT_FOUND':
				return __( 'SquidCore has no invoice for this order.', 'infixs-squidcore-erp' );
			case 'NFE_FILE_NOT_AVAILABLE':
				return __( 'The invoice file is not available in SquidCore yet. Try again in a few minutes.', 'infixs-squidcore-erp' );
			case 'WOO_INTEGRATION_NOT_ACTIVE':
				return __( 'The WooCommerce integration is not active in SquidCore. Activate it and try again.', 'infixs-squidcore-erp' );
			case 'WOO_INBOUND_SYNC_DISABLED':
				return __( 'SquidCore is not accepting orders from this store. Enable receiving orders in the WooCommerce integration in SquidCore and try again.', 'infixs-squidcore-erp' );
			case 'NFE_NOT_AUTHORIZED':
				return __( 'The invoice is not authorized.', 'infixs-squidcore-erp' );
			case 'NFE_NOT_CANCELLABLE':
				return __( 'This invoice can no longer be cancelled. Issue a return invoice (NF-e de devolução) in SquidCore.', 'infixs-squidcore-erp' );
			case 'NFE_EMISSION_REJECTED':
				/* translators: %s: message returned by SEFAZ. */
				return self::withSefazMessage( $error, __( 'The invoice was rejected: %s', 'infixs-squidcore-erp' ), __( 'The invoice was rejected by SEFAZ.', 'infixs-squidcore-erp' ) );
			case 'NFE_CANCEL_REJECTED':
				if ( ! empty( self::body( $error )['cancelWindowExpired'] ) ) {
					return __( 'The 24-hour cancellation window has ended. Issue a return invoice (NF-e de devolução) in SquidCore.', 'infixs-squidcore-erp' );
				}

				/* translators: %s: message returned by SEFAZ. */
				return self::withSefazMessage( $error, __( 'The cancellation was rejected: %s', 'infixs-squidcore-erp' ), __( 'The cancellation was rejected by SEFAZ.', 'infixs-squidcore-erp' ) );
			case 'NFE_BLOCKED':
				return self::apiMessage( $error ) ?? __( 'The invoice cannot be issued yet. Fix the pending items and try again.', 'infixs-squidcore-erp' );
			case 'NFE_EMISSION_RETRY_LATER':
			case 'NFE_PROVIDER_UNAVAILABLE':
				return __( 'The invoice service is temporarily unavailable. Try again in a few minutes.', 'infixs-squidcore-erp' );
		}

		if ( $status === 402 ) {
			return __( 'Your SquidCore plan does not allow issuing this invoice. Check your plan in SquidCore.', 'infixs-squidcore-erp' );
		}

		if ( $status === 400 ) {
			return self::apiMessage( $error ) ?? $error->get_error_message();
		}

		// A 404 without code comes from a SquidCore version without invoices for WooCommerce orders.
		if ( $status === 404 && $code === '' ) {
			return __( 'SquidCore did not find the invoice service for WooCommerce orders. Try again later or contact support.', 'infixs-squidcore-erp' );
		}

		if ( $status >= 500 ) {
			return __( 'The invoice service is temporarily unavailable. Try again in a few minutes.', 'infixs-squidcore-erp' );
		}

		return self::describe( $error );
	}

	/**
	 * The failed invoice request as an error for the store owner, ready for the plugin's own REST API. Statuses that
	 * WordPress would read as a problem with the logged-in user (401, 403) never pass through: transient failures
	 * become 502 and the rest 422.
	 */
	public static function invoiceError( \WP_Error $error, ?bool $tokenHasInvoiceScopes = null ): \WP_Error {
		return new \WP_Error( 'squidcore_invoice_error', self::describeInvoice( $error, $tokenHasInvoiceScopes ), [
			'status' => self::isTransient( $error ) ? 502 : 422,
			'upstreamStatus' => self::status( $error ),
			'upstreamCode' => self::code( $error ),
			'forbiddenReason' => self::forbiddenReason( $error, $tokenHasInvoiceScopes ),
		] );
	}

	/**
	 * Why SquidCore refused (403) an invoice request. SquidCore answers INSUFFICIENT_PERMISSIONS both when the token
	 * lacks the invoice scopes and when the user who generated it lacks the permission, so the scopes the handshake
	 * told for the token tell them apart.
	 *
	 * @param bool|null $tokenHasInvoiceScopes Whether the saved token carries the invoice scopes; null when unknown.
	 *
	 * @return string|null One of the FORBIDDEN_* constants, null when the request was not refused.
	 */
	public static function forbiddenReason( \WP_Error $error, ?bool $tokenHasInvoiceScopes = null ): ?string {
		if ( self::status( $error ) !== 403 ) {
			return null;
		}

		// SquidCore versions without the permission code refused only tokens without the invoice scopes.
		if ( self::code( $error ) !== self::INSUFFICIENT_PERMISSIONS ) {
			return self::FORBIDDEN_TOKEN_SCOPE;
		}

		// Plugin tokens always carry the other scopes (products.write when the order goes with the emission).
		if ( array_diff( self::missingPermissions( $error ), self::INVOICE_PERMISSIONS ) !== [] ) {
			return self::FORBIDDEN_USER_PERMISSION;
		}

		if ( $tokenHasInvoiceScopes === null ) {
			return self::FORBIDDEN_UNKNOWN;
		}

		return $tokenHasInvoiceScopes ? self::FORBIDDEN_USER_PERMISSION : self::FORBIDDEN_TOKEN_SCOPE;
	}

	/**
	 * Permission keys SquidCore said were missing (e.g. "tax_invoice.read").
	 *
	 * @return string[]
	 */
	public static function missingPermissions( \WP_Error $error ): array {
		$missing = self::body( $error )['missingPermissions'] ?? [];

		return is_array( $missing ) ? array_values( array_filter( $missing, 'is_string' ) ) : [];
	}

	/**
	 * Error code in the API answer (e.g. "NFE_BLOCKED"), blank when absent.
	 */
	public static function code( \WP_Error $error ): string {
		$code = self::body( $error )['code'] ?? '';

		return is_string( $code ) ? $code : '';
	}

	/**
	 * Decoded JSON body of the API error answer, empty when there is none.
	 */
	public static function body( \WP_Error $error ): array {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['body'] ) && is_array( $data['body'] ) ? $data['body'] : [];
	}

	/**
	 * Whether sending the same request again later can succeed: network failures, server errors,
	 * timeouts and rate limits. Other 4xx answers (bad token, no integration, invalid data) repeat.
	 */
	public static function isTransient( \WP_Error $error ): bool {
		$status = self::status( $error );

		return $status === 0 || $status >= 500 || in_array( $status, [ 408, 429 ], true );
	}

	/**
	 * @return int HTTP status, zero when the request got no answer.
	 */
	public static function status( \WP_Error $error ): int {
		$data = $error->get_error_data();

		return is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	}

	/**
	 * What to do once SquidCore stops accepting the "Conectar com SquidCore" authorization.
	 */
	public static function reconnectMessage(): string {
		return __( 'SquidCore no longer accepts the connection of this store: the authorization was revoked or ended. Reconnect with SquidCore in the plugin settings.', 'infixs-squidcore-erp' );
	}

	/**
	 * Whether the failed request was authorized by "Conectar com SquidCore" (OAuth) instead of a plugin token.
	 */
	public static function usesOAuth( \WP_Error $error ): bool {
		$data = $error->get_error_data();

		return is_array( $data ) && ( $data['auth'] ?? '' ) === 'oauth';
	}

	private static function forbiddenMessage( \WP_Error $error, string $reason ): string {
		$oauth = self::usesOAuth( $error );

		switch ( $reason ) {
			case self::FORBIDDEN_TOKEN_SCOPE:
				return $oauth
					? __( 'The SquidCore connection does not have permission to issue invoices (NF-e). Connect with SquidCore again and approve the NF-e permissions.', 'infixs-squidcore-erp' )
					: __( 'The access token does not have permission to issue invoices (NF-e). Generate a new token in SquidCore and save it in the plugin settings.', 'infixs-squidcore-erp' );
			case self::FORBIDDEN_UNKNOWN:
				return $oauth
					? __( 'SquidCore refused the request: the connection lacks the NF-e permissions, or the user who authorized it cannot reach every invoice of the organization. Connect with SquidCore again with a user who has access to the invoices (NF-e) of the organization and approve every permission.', 'infixs-squidcore-erp' )
					: __( 'SquidCore refused the request: the access token was generated before NF-e support, or the user who generated it cannot reach every invoice of the organization. Generate a new token with a user who has access to the invoices (NF-e) of the organization and save it in the plugin settings.', 'infixs-squidcore-erp' );
		}

		return self::userPermissionMessage( self::missingPermissions( $error ), $oauth );
	}

	/**
	 * The user who generated the token (or authorized the connection) lacks a permission: a new token or a new
	 * authorization from the same user would not help.
	 *
	 * @param string[] $missing Missing permission keys.
	 * @param bool $oauth The store connects through "Conectar com SquidCore".
	 */
	private static function userPermissionMessage( array $missing, bool $oauth = false ): string {
		if ( in_array( 'products.write', $missing, true ) ) {
			return $oauth
				? __( 'The user who authorized the connection is not allowed to edit products in SquidCore, which sending the order data requires. Connect with SquidCore again with a user who has that permission.', 'infixs-squidcore-erp' )
				: __( 'The user who generated the token is not allowed to edit products in SquidCore, which sending the order data requires. Generate the token with a user who has that permission.', 'infixs-squidcore-erp' );
		}

		if ( in_array( 'tax_invoice.write', $missing, true ) ) {
			return $oauth
				? __( 'The user who authorized the connection is not allowed to issue invoices (NF-e) in SquidCore. Connect with SquidCore again with a user who has that permission.', 'infixs-squidcore-erp' )
				: __( 'The user who generated the token is not allowed to issue invoices (NF-e) in SquidCore. Generate the token with a user who has that permission.', 'infixs-squidcore-erp' );
		}

		if ( $missing === [] || array_diff( $missing, self::INVOICE_PERMISSIONS ) === [] ) {
			return $oauth
				? __( 'The user who authorized the connection is not allowed to see or cancel every invoice of the organization. Connect with SquidCore again with a user who has access to the invoices (NF-e) of the organization.', 'infixs-squidcore-erp' )
				: __( 'The user who generated the token is not allowed to see or cancel every invoice of the organization. Generate the token with a user who has access to the invoices (NF-e) of the organization.', 'infixs-squidcore-erp' );
		}

		$keys = implode( ', ', array_map( 'sanitize_text_field', $missing ) );

		if ( $oauth ) {
			/* translators: %s: permission keys of SquidCore, e.g. "customers.write". */
			return sprintf( __( 'The user who authorized the connection does not have the SquidCore permission this action needs (%s). Connect with SquidCore again with a user who has it.', 'infixs-squidcore-erp' ), $keys );
		}

		/* translators: %s: permission keys of SquidCore, e.g. "customers.write". */
		return sprintf( __( 'The user who generated the token does not have the SquidCore permission this action needs (%s). Generate the token with a user who has it.', 'infixs-squidcore-erp' ), $keys );
	}

	/**
	 * The monthly invoice limit of the plan, with the use of the month when the 402 tells it.
	 */
	private static function planLimitMessage( \WP_Error $error ): string {
		$body = self::body( $error );
		$metric = $body['metric'] ?? '';
		$monthly = $metric === '' || $metric === 'taxInvoicesPerMonth';

		if ( $monthly && isset( $body['limit'], $body['used'] ) && is_numeric( $body['limit'] ) && is_numeric( $body['used'] ) ) {
			return sprintf(
				/* translators: 1: invoices issued this month, 2: invoices the plan allows per month. */
				__( 'The invoice limit of your SquidCore plan was reached (%1$d of %2$d this month). Upgrade your plan and try again.', 'infixs-squidcore-erp' ),
				(int) $body['used'],
				(int) $body['limit']
			);
		}

		return __( 'The invoice limit of your SquidCore plan was reached. Upgrade your plan and try again.', 'infixs-squidcore-erp' );
	}

	/**
	 * Message the API wrote in the error answer, null when absent.
	 */
	private static function apiMessage( \WP_Error $error ): ?string {
		$message = self::body( $error )['message'] ?? null;

		if ( is_array( $message ) ) {
			$message = implode( ' ', array_filter( $message, 'is_string' ) );
		}

		return is_string( $message ) && trim( $message ) !== '' ? sanitize_text_field( $message ) : null;
	}

	private static function withSefazMessage( \WP_Error $error, string $format, string $fallback ): string {
		$body = self::body( $error );
		$sefaz = $body['mensagemSefaz'] ?? ( $body['invoice']['mensagemSefaz'] ?? null );

		return is_string( $sefaz ) && trim( $sefaz ) !== '' ? sprintf( $format, sanitize_text_field( $sefaz ) ) : $fallback;
	}
}
