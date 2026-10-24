<?php

namespace Infixs\SquidCore\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Scopes of a SquidCore credential (plugin token or OAuth access token), matched as SquidCore matches them.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class TokenScopes {

	/**
	 * Scopes the invoice (NF-e) routes need. Tokens generated before them get 403 on those routes.
	 */
	public const INVOICE = [ 'tax_invoice.read', 'tax_invoice.write', 'tax_invoice.cancel' ];

	/**
	 * Whether the scopes cover every invoice permission.
	 *
	 * @param array $scopes Credential scopes.
	 */
	public static function coverInvoices( array $scopes ): bool {
		foreach ( self::INVOICE as $key ) {
			if ( ! self::covers( $scopes, $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the scopes cover a permission key: the key itself, its whole resource ("tax_invoice") or
	 * "<resource>.manage".
	 *
	 * @param array $scopes Credential scopes.
	 */
	public static function covers( array $scopes, string $key ): bool {
		foreach ( array_filter( $scopes, 'is_string' ) as $scope ) {
			$scope = str_replace( ':', '.', trim( $scope ) );
			$dot = strrpos( $scope, '.' );

			if ( $scope === $key
				|| ( $dot === false && $scope !== '' && strpos( $key, $scope . '.' ) === 0 )
				|| ( $dot !== false && substr( $scope, $dot + 1 ) === 'manage' && strpos( $key, substr( $scope, 0, $dot ) . '.' ) === 0 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Scopes of an OAuth `scope` string (space separated).
	 *
	 * @return string[]
	 */
	public static function parse( string $scope ): array {
		$scopes = preg_split( '/\s+/', trim( $scope ), -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $scopes ) ? $scopes : [];
	}
}
