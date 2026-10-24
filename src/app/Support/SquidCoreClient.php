<?php

namespace Infixs\SquidCore\Support;

use Infixs\SquidCore\Support\Credentials\CredentialProvider;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for the SquidCore API.
 *
 * Authenticates with a Bearer token (the "Conectar com SquidCore" access token or the plugin token of the manual
 * connection), read from the credential provider on every request, and retries transient failures
 * (network errors / 5xx) with a short exponential backoff.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class SquidCoreClient {

	private const API_VERSION = 'v1';

	private const TIMEOUT = 15;

	/**
	 * Total request attempts: 1 initial + 2 retries.
	 */
	private const MAX_ATTEMPTS = 3;

	private const DOWNLOAD_TIMEOUT = 30;

	/**
	 * Largest file accepted by download(), in bytes.
	 */
	private const DOWNLOAD_MAX_BYTES = 10485760;

	/**
	 * @var string
	 */
	protected $baseUrl;

	/**
	 * @var CredentialProvider
	 */
	protected $credentials;

	/**
	 * @var int
	 */
	protected $maxAttempts;

	/**
	 * Seconds a download may take.
	 *
	 * @var int
	 */
	protected $downloadTimeout = self::DOWNLOAD_TIMEOUT;

	/**
	 * @param string|null $baseUrl
	 * @param CredentialProvider $credentials
	 * @param int $maxAttempts Total attempts per request; interactive calls use 1 to fail fast.
	 */
	public function __construct( ?string $baseUrl, CredentialProvider $credentials, int $maxAttempts = self::MAX_ATTEMPTS ) {
		$this->baseUrl = rtrim( (string) $baseUrl, '/' );
		$this->credentials = $credentials;
		$this->maxAttempts = max( 1, $maxAttempts );
	}

	/**
	 * Whether the client has enough configuration to reach the API.
	 */
	public function isConfigured(): bool {
		return $this->baseUrl !== '' && $this->credentials->token() !== '';
	}

	/**
	 * Same client with another number of attempts per request; interactive calls use 1 to fail fast.
	 */
	public function withMaxAttempts( int $maxAttempts ): self {
		$client = clone $this;
		$client->maxAttempts = max( 1, $maxAttempts );

		return $client;
	}

	/**
	 * Same client with a shorter wait for downloads, for a file fetched while a request waits on it (never longer than
	 * the default).
	 */
	public function withDownloadTimeout( int $seconds ): self {
		$client = clone $this;
		$client->downloadTimeout = min( self::DOWNLOAD_TIMEOUT, max( 1, $seconds ) );

		return $client;
	}

	/**
	 * POST request.
	 *
	 * @param string $endpoint Endpoint relative to /v1 (e.g. "integrations/apps/woocommerce/events").
	 * @param array $data JSON body.
	 *
	 * @return array|\WP_Error
	 */
	public function post( string $endpoint, array $data = [] ) {
		return $this->request( 'POST', $endpoint, [
			'body' => wp_json_encode( $data ),
		] );
	}

	/**
	 * GET request.
	 *
	 * @param string $endpoint
	 * @param array $params Query string parameters.
	 *
	 * @return array|\WP_Error
	 */
	public function get( string $endpoint, array $params = [] ) {
		$query = $params ? '?' . http_build_query( $params ) : '';
		return $this->request( 'GET', $endpoint . $query );
	}

	/**
	 * Downloads a file (one attempt, no JSON decoding).
	 *
	 * @param string $endpoint Endpoint relative to /v1.
	 * @param array $params Query string parameters.
	 *
	 * @return array{body: string, contentType: string, filename: string}|\WP_Error A failure answer keeps the
	 *         status and the decoded JSON error body, as the other requests do.
	 */
	public function download( string $endpoint, array $params = [] ) {
		$token = $this->credentials->token();

		if ( $this->baseUrl === '' || $token === '' ) {
			return $this->notConfigured();
		}

		$query = $params ? '?' . http_build_query( $params ) : '';

		$response = wp_remote_request( $this->getUrl( $endpoint . $query ), [
			'method' => 'GET',
			'timeout' => $this->downloadTimeout,
			'limit_response_size' => self::DOWNLOAD_MAX_BYTES + 1,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handleResponse( $response, $status_code, $token );
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( strlen( $body ) > self::DOWNLOAD_MAX_BYTES ) {
			return new \WP_Error(
				'squidcore_download_too_large',
				__( 'The file returned by SquidCore is larger than the allowed size.', 'infixs-squidcore-erp' ),
				[ 'status' => 502 ]
			);
		}

		return [
			'body' => $body,
			'contentType' => strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) ),
			'filename' => $this->filenameFromDisposition( (string) wp_remote_retrieve_header( $response, 'content-disposition' ) ),
		];
	}

	/**
	 * @param string $method
	 * @param string $endpoint
	 * @param array $args Extra wp_remote_request args (body, ...).
	 *
	 * @return array|\WP_Error
	 */
	protected function request( string $method, string $endpoint, array $args = [] ) {
		$token = $this->credentials->token();

		if ( $this->baseUrl === '' || $token === '' ) {
			return $this->notConfigured();
		}

		$url = $this->getUrl( $endpoint );

		$request_args = array_merge( [
			'method' => $method,
			'timeout' => self::TIMEOUT,
			'headers' => $this->getHeaders( $token ),
		], $args );

		$last_error = null;

		for ( $attempt = 1; $attempt <= $this->maxAttempts; $attempt++ ) {
			$response = wp_remote_request( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
			} else {
				$status_code = (int) wp_remote_retrieve_response_code( $response );

				if ( $status_code < 500 ) {
					return $this->handleResponse( $response, $status_code, $token );
				}

				$last_error = new \WP_Error(
					'squidcore_server_error',
					/* translators: %d: HTTP status code. */
					sprintf( __( 'SquidCore API responded with status %d.', 'infixs-squidcore-erp' ), $status_code ),
					[ 'status' => $status_code ]
				);
			}

			// Short exponential backoff before retrying (1s, 2s).
			if ( $attempt < $this->maxAttempts ) {
				sleep( 2 ** ( $attempt - 1 ) );
			}
		}

		return $last_error;
	}

	/**
	 * @param array $response wp_remote_* response.
	 * @param int $status_code
	 * @param string $token Token the request carried.
	 *
	 * @return array|\WP_Error A failure keeps the status, the decoded body and how the store connects (`auth`), so
	 *                         the messages can tell the store owner what to do.
	 */
	protected function handleResponse( $response, int $status_code, string $token ) {
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			if ( $status_code === 401 ) {
				$this->credentials->rejected( $token );
			}

			$message = is_array( $data ) && isset( $data['message'] )
				? ( is_array( $data['message'] ) ? implode( ' ', $data['message'] ) : (string) $data['message'] )
				: wp_remote_retrieve_response_message( $response );

			return new \WP_Error(
				'squidcore_api_error',
				/* translators: 1: HTTP status code, 2: error message returned by the API. */
				sprintf( __( 'SquidCore API error (%1$d): %2$s', 'infixs-squidcore-erp' ), $status_code, $message ),
				[ 'status' => $status_code, 'body' => $data, 'auth' => $this->credentials->method() ]
			);
		}

		return is_array( $data ) ? $data : [];
	}

	/**
	 * No request goes out. An OAuth connection SquidCore refused before answers as that refusal did, so every
	 * message keeps telling to reconnect.
	 */
	protected function notConfigured(): \WP_Error {
		if ( $this->baseUrl !== '' && $this->credentials->method() === CredentialProvider::METHOD_OAUTH ) {
			return new \WP_Error( 'squidcore_reconnect_required', ApiErrorMessage::reconnectMessage(), [
				'status' => 401,
				'auth' => CredentialProvider::METHOD_OAUTH,
			] );
		}

		return new \WP_Error( 'squidcore_not_configured', __( 'SquidCore connection is not configured.', 'infixs-squidcore-erp' ) );
	}

	/**
	 * File name of a Content-Disposition header, reduced to a safe base name; blank when absent.
	 */
	protected function filenameFromDisposition( string $disposition ): string {
		if ( ! preg_match( '/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches ) ) {
			return '';
		}

		return sanitize_file_name( wp_basename( rawurldecode( $matches[1] ) ) );
	}

	protected function getUrl( string $endpoint ): string {
		return $this->baseUrl . '/' . self::API_VERSION . '/' . ltrim( $endpoint, '/' );
	}

	protected function getHeaders( string $token ): array {
		return [
			'Content-Type' => 'application/json',
			'Authorization' => 'Bearer ' . $token,
		];
	}
}
