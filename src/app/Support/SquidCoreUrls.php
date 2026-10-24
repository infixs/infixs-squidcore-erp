<?php

namespace Infixs\SquidCore\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Addresses of the SquidCore API and panel.
 *
 * The store owner never types them: the plugin uses the official addresses from the config,
 * and developers or staging sites can point elsewhere with constants in wp-config.php.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class SquidCoreUrls {

	public const API_URL_CONSTANT = 'INFIXS_SQUIDCORE_API_URL';

	public const APP_URL_CONSTANT = 'INFIXS_SQUIDCORE_APP_URL';

	/**
	 * @var string
	 */
	protected $apiUrl;

	/**
	 * @var bool
	 */
	protected $apiUrlOverridden;

	/**
	 * @var string
	 */
	protected $appUrl;

	/**
	 * @param string $defaultApiUrl Official API address, used unless INFIXS_SQUIDCORE_API_URL is set.
	 * @param string $defaultAppUrl Official panel address, used unless INFIXS_SQUIDCORE_APP_URL is set.
	 */
	public function __construct( string $defaultApiUrl, string $defaultAppUrl ) {
		$api_override = $this->constantValue( self::API_URL_CONSTANT );
		$app_override = $this->constantValue( self::APP_URL_CONSTANT );

		$this->apiUrlOverridden = $api_override !== '';
		$this->apiUrl = untrailingslashit( $this->apiUrlOverridden ? $api_override : $defaultApiUrl );
		$this->appUrl = untrailingslashit( $app_override !== '' ? $app_override : $defaultAppUrl );
	}

	public function apiUrl(): string {
		return $this->apiUrl;
	}

	public function isApiUrlOverridden(): bool {
		return $this->apiUrlOverridden;
	}

	public function appUrl(): string {
		return $this->appUrl;
	}

	/**
	 * Whether the API URL can receive the token. Safe to call before `init`: it translates nothing.
	 */
	public function isApiUrlUsable(): bool {
		return $this->apiUrlProblem() === null;
	}

	/**
	 * @return \WP_Error|null Null when the API URL can receive the token.
	 */
	public function apiUrlError(): ?\WP_Error {
		switch ( $this->apiUrlProblem() ) {
			case 'invalid_api_url':
				return new \WP_Error(
					'invalid_api_url',
					/* translators: %s: name of the wp-config.php constant. */
					sprintf( __( 'The %s constant in wp-config.php is not a valid URL. Use an address starting with https://.', 'infixs-squidcore-erp' ), self::API_URL_CONSTANT ),
					[ 'status' => 400 ]
				);
			case 'insecure_api_url':
				return new \WP_Error(
					'insecure_api_url',
					/* translators: %s: name of the wp-config.php constant. */
					sprintf( __( 'The %s constant in wp-config.php must start with https://, otherwise the access token would travel unencrypted.', 'infixs-squidcore-erp' ), self::API_URL_CONSTANT ),
					[ 'status' => 400 ]
				);
		}

		return null;
	}

	/**
	 * The token goes in a request header, so plain http is only accepted when the request stays on this machine
	 * or network, or on a site marked as local. Development and staging sites are often public, so they need https.
	 * Only an override can fail: the official address is https.
	 *
	 * @return string|null Error code, null when the URL is usable.
	 */
	private function apiUrlProblem(): ?string {
		$scheme = strtolower( (string) wp_parse_url( $this->apiUrl, PHP_URL_SCHEME ) );
		$host = strtolower( (string) wp_parse_url( $this->apiUrl, PHP_URL_HOST ) );

		if ( $host === '' || ! in_array( $scheme, [ 'https', 'http' ], true ) ) {
			return 'invalid_api_url';
		}

		if ( $scheme === 'http' && ! $this->isLocalHost( $host ) && wp_get_environment_type() !== 'local' ) {
			return 'insecure_api_url';
		}

		return null;
	}

	/**
	 * Loopback names, the Docker host and private or reserved IP addresses.
	 */
	private function isLocalHost( string $host ): bool {
		$host = trim( $host, '[]' );

		if ( in_array( $host, [ 'localhost', 'host.docker.internal' ], true ) || substr( $host, -10 ) === '.localhost' ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) === false ) {
			return false;
		}

		return filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
	}

	/**
	 * @return string Blank when the constant is not defined, not a string or empty.
	 */
	private function constantValue( string $name ): string {
		if ( ! defined( $name ) ) {
			return '';
		}

		$value = constant( $name );

		return is_string( $value ) ? trim( $value ) : '';
	}
}
