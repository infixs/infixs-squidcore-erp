<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

use Infixs\SquidCore\Support\SquidCoreClient;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, in SquidCore, the NF-e numbering and the tax defaults of the company (scope `tax_invoice.read`). The plugin
 * cannot change them: the store owner adjusts them in the SquidCore panel.
 *
 * Every method returns the data, or the WP_Error of the client. Answers come as the bare value or wrapped in `data`.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class NfeNumberingApi {

	/**
	 * Model of the NF-e (65 is the NFC-e).
	 */
	private const MODEL = '55';

	/**
	 * @var SquidCoreClient
	 */
	protected $client;

	/**
	 * @param SquidCoreClient $client Client the person waits for (one attempt).
	 */
	public function __construct( SquidCoreClient $client ) {
		$this->client = $client;
	}

	/**
	 * NF-e series of the company: the configured ones with their next number, and the ones that only have authorized
	 * invoices, whose next number follows the last one (SquidCore would create them on the next emission).
	 *
	 * @return array<int, array{series: string, nextNumber: int, configured: bool, lastIssued: int|null}>|\WP_Error
	 *         Sorted by series.
	 */
	public function series() {
		$configured = $this->data( $this->client->get( 'tax-invoice/nfe/numeracoes', [ 'modelo' => self::MODEL ] ) );

		if ( is_wp_error( $configured ) ) {
			return $configured;
		}

		$issued = $this->data( $this->client->get( 'tax-invoice/nfe/numeracoes/emitidos', [ 'modelo' => self::MODEL ] ) );

		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$last_issued = [];

		foreach ( $this->rows( $issued ) as $row ) {
			$series = $this->seriesKey( $row['serie'] ?? null );

			if ( $series !== null && isset( $row['ultimoNumeroEmitido'] ) && is_numeric( $row['ultimoNumeroEmitido'] ) ) {
				$last_issued[ $series ] = (int) $row['ultimoNumeroEmitido'];
			}
		}

		$series_list = [];

		foreach ( $this->rows( $configured ) as $row ) {
			$series = $this->seriesKey( $row['serie'] ?? null );

			if ( $series === null || ! isset( $row['proximoNumero'] ) || ! is_numeric( $row['proximoNumero'] ) ) {
				continue;
			}

			$last = isset( $row['ultimoNumeroEmitido'] ) && is_numeric( $row['ultimoNumeroEmitido'] ) ? (int) $row['ultimoNumeroEmitido'] : ( $last_issued[ $series ] ?? null );

			$series_list[ $series ] = [
				'series' => $series,
				'nextNumber' => (int) $row['proximoNumero'],
				'configured' => true,
				'lastIssued' => $last,
			];
		}

		foreach ( $last_issued as $series => $last ) {
			$series = (string) $series;

			if ( ! isset( $series_list[ $series ] ) ) {
				$series_list[ $series ] = [
					'series' => $series,
					'nextNumber' => $last + 1,
					'configured' => false,
					'lastIssued' => $last,
				];
			}
		}

		usort( $series_list, function ( array $a, array $b ): int {
			return (int) $a['series'] <=> (int) $b['series'];
		} );

		return $series_list;
	}

	/**
	 * Tax defaults of the company.
	 *
	 * @return array{cfopInternal: string, cfopInterstate: string, operationNature: string, origin: string}|\WP_Error
	 */
	public function fiscalDefaults() {
		$settings = $this->data( $this->client->get( 'tax-invoice/fiscal-settings' ) );

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		return [
			'cfopInternal' => $this->text( $settings['cfopVendaInterna'] ?? '' ),
			'cfopInterstate' => $this->text( $settings['cfopVendaInterestadual'] ?? '' ),
			'operationNature' => $this->text( $settings['naturezaOperacaoVenda'] ?? '' ),
			'origin' => $this->text( $settings['icmsOrigemPadrao'] ?? '' ),
		];
	}

	/**
	 * The answer without its `data` wrapper.
	 *
	 * @param array|\WP_Error $response
	 *
	 * @return array|\WP_Error
	 */
	private function data( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( is_array( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) ) {
			return $response['data'];
		}

		return is_array( $response ) ? $response : [];
	}

	/**
	 * @return array[] Rows of a list answer, malformed ones left out.
	 */
	private function rows( array $list ): array {
		return array_values( array_filter( $list, 'is_array' ) );
	}

	/**
	 * Series as SquidCore and the access key compare it: the number without leading zeros. Null when not a number.
	 *
	 * @param mixed $series
	 */
	private function seriesKey( $series ): ?string {
		$series = is_scalar( $series ) ? trim( (string) $series ) : '';

		return preg_match( '/^\d{1,3}$/', $series ) ? (string) (int) $series : null;
	}

	/**
	 * @param mixed $value
	 */
	private function text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}
}
