<?php

namespace Infixs\SquidCore\Integrations\WebmaniaBR;

defined( 'ABSPATH' ) || exit;

/**
 * Invoices WebmaniaBR issued for the orders, read from the order meta it keeps (`nfe`, a list of entries, the newest
 * last) through the WooCommerce CRUD, so it works with and without HPOS, and with WebmaniaBR inactive.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class WebmaniaInvoiceHistory {

	/**
	 * Models of the service invoices (NFS-e) kept in the same meta.
	 */
	private const SERVICE_MODELS = [ 'nfse', 'lote_rps' ];

	/**
	 * Statuses of an NF-e that never came to be, or no longer counts.
	 */
	private const VOID_STATUSES = [ 'reprovado', 'cancelado' ];

	/**
	 * Statuses of an NF-e that used its number (a cancelled number cannot be used again).
	 */
	private const NUMBERED_STATUSES = [ 'aprovado', 'cancelado' ];

	/**
	 * Model of the NF-e in the access key (65 is the NFC-e, numbered apart).
	 */
	private const NFE_KEY_MODEL = '55';

	/**
	 * Orders read by default by lastNumbers(), the most recent first.
	 */
	public const LAST_NUMBERS_ORDERS = 500;

	/**
	 * The WebmaniaBR NF-e that rules out an automatic invoice of SquidCore: the first entry that is not a service
	 * invoice and was not rejected or cancelled. Any other status counts (approved, processing, blank or unknown),
	 * as WebmaniaBR itself does not issue again for an order that has an entry.
	 *
	 * @return array{number: string, series: string, status: string}|null Null when the order has no such entry.
	 */
	public function blocksAutomaticEmission( \WC_Order $order ): ?array {
		foreach ( $this->entries( $order ) as $entry ) {
			$model = strtolower( trim( $this->text( $entry['modelo'] ?? '' ) ) );
			$status = strtolower( trim( $this->text( $entry['status'] ?? '' ) ) );

			if ( in_array( $model, self::SERVICE_MODELS, true ) || in_array( $status, self::VOID_STATUSES, true ) ) {
				continue;
			}

			$number = trim( $this->text( $entry['n_nfe'] ?? '' ) );

			return [
				'number' => $number === '0' ? '' : $number,
				'series' => trim( $this->text( $entry['n_serie'] ?? '' ) ),
				'status' => $status,
			];
		}

		return null;
	}

	/**
	 * Last NF-e number WebmaniaBR used per issuer CNPJ and series, from the history of the most recent orders. Only
	 * NF-e (model 55) approved or cancelled count, read from their access key. Invoices issued in the Webmania panel
	 * never reach the orders, and the history does not say whether they were test (homologation) invoices.
	 *
	 * @param int $orders Most recent orders with a history to read.
	 *
	 * @return array{series: array<int, array{cnpj: string, series: string, lastNumber: int}>, ordersScanned: int}
	 *         Series sorted by CNPJ and series.
	 */
	public function lastNumbers( int $orders = self::LAST_NUMBERS_ORDERS ): array {
		$ids = function_exists( 'wc_get_orders' ) ? wc_get_orders( [
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the orders with a WebmaniaBR history, read on request and cached. Both order storages honour meta_key; the posts one ignores meta_query.
			'meta_key' => WebmaniaBR::ORDER_META,
			'meta_compare' => 'EXISTS',
			'orderby' => 'ID',
			'order' => 'DESC',
			'limit' => max( 1, $orders ),
			'return' => 'ids',
		] ) : [];
		$ids = is_array( $ids ) ? $ids : [];
		$last = [];

		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			foreach ( $this->entries( $order ) as $entry ) {
				$number = $this->numbered( $entry );

				if ( $number === null ) {
					continue;
				}

				$key = $number['cnpj'] . '/' . $number['series'];
				$last[ $key ] = [
					'cnpj' => $number['cnpj'],
					'series' => $number['series'],
					'lastNumber' => max( $number['number'], $last[ $key ]['lastNumber'] ?? 0 ),
				];
			}
		}

		usort( $last, function ( array $a, array $b ): int {
			return [ $a['cnpj'], (int) $a['series'] ] <=> [ $b['cnpj'], (int) $b['series'] ];
		} );

		return [
			'series' => array_values( $last ),
			'ordersScanned' => count( $ids ),
		];
	}

	/**
	 * Issuer CNPJ, series and number of an approved or cancelled NF-e entry, read from its access key (positions
	 * 7-20, 23-25 and 26-34); null for anything else (NFC-e, NFS-e, rejected, without a valid key).
	 *
	 * @return array{cnpj: string, series: string, number: int}|null
	 */
	private function numbered( array $entry ): ?array {
		$model = strtolower( trim( $this->text( $entry['modelo'] ?? '' ) ) );
		$status = strtolower( trim( $this->text( $entry['status'] ?? '' ) ) );
		$key = (string) preg_replace( '/\D+/', '', $this->text( $entry['chave_acesso'] ?? '' ) );

		if ( ! in_array( $model, [ '', 'nfe' ], true ) || ! in_array( $status, self::NUMBERED_STATUSES, true ) || strlen( $key ) !== 44 ) {
			return null;
		}

		$number = (int) substr( $key, 25, 9 );

		if ( substr( $key, 20, 2 ) !== self::NFE_KEY_MODEL || $number <= 0 ) {
			return null;
		}

		return [
			'cnpj' => substr( $key, 6, 14 ),
			'series' => (string) (int) substr( $key, 22, 3 ),
			'number' => $number,
		];
	}

	/**
	 * @return array[] Entries of the history, malformed ones left out.
	 */
	private function entries( \WC_Order $order ): array {
		$entries = $order->get_meta( WebmaniaBR::ORDER_META, true );

		if ( ! is_array( $entries ) ) {
			return [];
		}

		return array_values( array_filter( $entries, 'is_array' ) );
	}

	/**
	 * @param mixed $value
	 */
	private function text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}
}
