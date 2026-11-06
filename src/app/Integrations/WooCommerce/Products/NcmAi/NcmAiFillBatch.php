<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi;

use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCode;
use Infixs\SquidCore\Integrations\WooCommerce\SyncDispatcher;
use Infixs\SquidCore\Services\SyncService;
use Infixs\SquidCore\Support\ApiErrorMessage;

defined( 'ABSPATH' ) || exit;

/**
 * One batch of the AI filling: SquidCore fills the NCM of up to ProductNcmApi::FILL_MAX products (only where it has
 * none), and each NCM it filled comes to the WooCommerce product (ProductNcmWriter).
 *
 * The plugin does not know which products reached SquidCore, so the batch asks for all of them: the ones SquidCore
 * does not know yet (not_synced) are sent to it right away (the push the plugin always uses, while the catalog sync is
 * on) and asked once more in the same batch. With the catalog sync off they stay without NCM, with that reason.
 *
 * Some products may be left pending, to be asked again in a while, without a row: the ones the AI did not answer when
 * it answered for at most as many as it failed (the provider is down, out of credit or rate limited, not one product
 * that went wrong), and the ones sent to SquidCore when the second ask failed (SquidCore may even have filled them: the
 * next ask copies that NCM without the AI).
 *
 * @package Infixs\SquidCore
 * @since   1.7.0
 */
class NcmAiFillBatch {

	/**
	 * Where the NCM of a filled row comes from: the AI of this batch, or SquidCore, which already had one and told it.
	 */
	public const SOURCE_AI = 'ai';

	public const SOURCE_SQUIDCORE = 'squidcore';

	/**
	 * @var ProductNcmApi
	 */
	protected $api;

	/**
	 * @var ProductNcmWriter
	 */
	protected $writer;

	/**
	 * @var SyncDispatcher
	 */
	protected $dispatcher;

	/**
	 * @var SyncService
	 */
	protected $sync;

	public function __construct( ProductNcmApi $api, ProductNcmWriter $writer, SyncDispatcher $dispatcher, SyncService $sync ) {
		$this->api = $api;
		$this->writer = $writer;
		$this->dispatcher = $dispatcher;
		$this->sync = $sync;
	}

	/**
	 * @param int[] $ids WooCommerce products, ProductNcmApi::FILL_MAX at most.
	 *
	 * @return array{rows: array{filled: array[], failed: array[]}, pending: int[], error: \WP_Error|null}|\WP_Error
	 *         rows: the lists of the run, one row per product settled, in the order asked (filled: {productId, ncm,
	 *         descricao, confianca, source}; failed: {productId, reason, message}); pending: the products to ask again
	 *         in a while, without a row; error: why the second ask failed. The error of SquidCore when the batch could
	 *         not be asked at all (nothing written).
	 */
	public function process( array $ids ) {
		$answer = $this->api->fill( $ids );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$outcome = $this->read( $ids, $answer );
		$unsynced = array_keys( array_filter( $outcome['failed'], function ( $failure ) {
			return $failure['reason'] === NcmAiLabels::NOT_SYNCED;
		} ) );
		$pending = [];
		$error = null;

		if ( $unsynced ) {
			$again = $this->askAgainAfterPush( $unsynced, $outcome );
			$outcome = $again['outcome'];
			$error = $again['error'];
			$pending = $error !== null ? $again['pushed'] : [];
		}

		$asked = array_values( array_diff( $ids, $pending ) );
		$unanswered = self::unanswered( $asked, $outcome );

		if ( $unanswered ) {
			$pending = array_merge( $pending, $unanswered );
		}

		return [
			'rows' => $this->rows( array_values( array_diff( $ids, $pending ) ), $outcome ),
			'pending' => array_values( array_intersect( $ids, $pending ) ),
			'error' => $error,
		];
	}

	/**
	 * The products the AI did not answer, when it answered (an NCM or "no valid NCM") for at most as many as it failed:
	 * the AI is not answering now, and they wait to be asked again. Empty when the AI answered for most of them (the
	 * ones it failed are told as such).
	 *
	 * @param int[] $ids Products asked, without the ones already pending.
	 *
	 * @return int[]
	 */
	private static function unanswered( array $ids, array $outcome ): array {
		$answered = 0;
		$unanswered = [];

		foreach ( $ids as $id ) {
			$reason = isset( $outcome['filled'][ $id ] ) ? null : ( $outcome['failed'][ $id ]['reason'] ?? NcmAiLabels::FAILED );

			if ( $reason === null || $reason === NcmAiLabels::INVALID ) {
				++$answered;
			} elseif ( $reason === NcmAiLabels::FAILED ) {
				$unanswered[] = $id;
			}
		}

		return count( $unanswered ) >= $answered ? $unanswered : [];
	}

	/**
	 * The answer by product: only the products asked, a product filled is not also a failure.
	 *
	 * @return array{filled: array<int, array>, failed: array<int, array{reason: string, message: string, ncm: string,
	 *         descricao: string}>} ncm and descricao: the NCM SquidCore already had (has_ncm) and its official
	 *         description.
	 */
	private function read( array $ids, array $answer ): array {
		$asked = array_flip( $ids );
		$filled = [];
		$failed = [];

		foreach ( isset( $answer['filled'] ) && is_array( $answer['filled'] ) ? $answer['filled'] : [] as $item ) {
			$id = is_array( $item ) ? (int) ( $item['wcProductId'] ?? 0 ) : 0;

			if ( isset( $asked[ $id ] ) ) {
				$filled[ $id ] = $item;
			}
		}

		foreach ( isset( $answer['failed'] ) && is_array( $answer['failed'] ) ? $answer['failed'] : [] as $item ) {
			$id = is_array( $item ) ? (int) ( $item['wcProductId'] ?? 0 ) : 0;

			if ( ! isset( $asked[ $id ] ) || isset( $filled[ $id ] ) ) {
				continue;
			}

			$failed[ $id ] = [
				'reason' => NcmAiLabels::reasonOf( $item ),
				'message' => NcmAiLabels::messageOf( $item ),
				'ncm' => is_scalar( $item['ncm'] ?? null ) ? (string) $item['ncm'] : '',
				'descricao' => is_string( $item['descricao'] ?? null ) ? $item['descricao'] : '',
			];
		}

		return [
			'filled' => $filled,
			'failed' => $failed,
		];
	}

	/**
	 * Sends the products SquidCore did not know and asks for them again.
	 *
	 * @param int[] $ids
	 *
	 * @return array{outcome: array, pushed: int[], error: \WP_Error|null} The products sent and, when the second ask
	 *         failed, why (their answer is then the one of the first ask).
	 */
	private function askAgainAfterPush( array $ids, array $outcome ): array {
		if ( ! $this->sync->options()['catalog'] ) {
			foreach ( $ids as $id ) {
				$outcome['failed'][ $id ] = self::failure( NcmAiLabels::CATALOG_OFF );
			}

			return [ 'outcome' => $outcome, 'pushed' => [], 'error' => null ];
		}

		$pushed = [];

		foreach ( $ids as $id ) {
			$result = $this->dispatcher->pushNow( 'product.updated', $id );

			if ( $result === true ) {
				$pushed[] = $id;
				continue;
			}

			$outcome['failed'][ $id ]['message'] = sprintf(
				/* translators: %s: why the product could not be sent. */
				__( 'The product could not be sent to SquidCore: %s', 'infixs-squidcore-erp' ),
				ApiErrorMessage::describe( $result )
			);
		}

		if ( ! $pushed ) {
			return [ 'outcome' => $outcome, 'pushed' => [], 'error' => null ];
		}

		$answer = $this->api->fill( $pushed );

		if ( is_wp_error( $answer ) ) {
			return [ 'outcome' => $outcome, 'pushed' => $pushed, 'error' => $answer ];
		}

		$again = $this->read( $pushed, $answer );

		foreach ( $pushed as $id ) {
			unset( $outcome['failed'][ $id ] );
		}

		$outcome['filled'] += $again['filled'];
		$outcome['failed'] = $again['failed'] + $outcome['failed'];

		return [ 'outcome' => $outcome, 'pushed' => $pushed, 'error' => null ];
	}

	/**
	 * Writes the NCM filled on the WooCommerce products and lists each product of the batch once, in the order asked.
	 */
	private function rows( array $ids, array $outcome ): array {
		$rows = [
			NcmAiFillRepository::FILLED => [],
			NcmAiFillRepository::FAILED => [],
		];

		foreach ( $ids as $id ) {
			if ( isset( $outcome['filled'][ $id ] ) ) {
				$item = $outcome['filled'][ $id ];
				$written = $this->writer->write( $id, (string) ( $item['ncm'] ?? '' ) );

				if ( $written === ProductNcmWriter::WRITTEN ) {
					$rows[ NcmAiFillRepository::FILLED ][] = self::filledRow( $id, $item, self::SOURCE_AI );
				} else {
					$rows[ NcmAiFillRepository::FAILED ][] = self::failedRow( $id, self::notWrittenReason( $written ), '' );
				}

				continue;
			}

			$failure = $outcome['failed'][ $id ] ?? self::failure( NcmAiLabels::FAILED );

			// SquidCore already had an NCM and told it: the product here gets it too, with its official description.
			if ( $failure['reason'] === NcmAiLabels::HAS_NCM && $failure['ncm'] !== '' && $this->writer->write( $id, $failure['ncm'] ) === ProductNcmWriter::WRITTEN ) {
				$rows[ NcmAiFillRepository::FILLED ][] = self::filledRow( $id, [ 'ncm' => $failure['ncm'], 'descricao' => $failure['descricao'] ], self::SOURCE_SQUIDCORE );
				continue;
			}

			$rows[ NcmAiFillRepository::FAILED ][] = self::failedRow( $id, $failure['reason'], $failure['message'] );
		}

		return $rows;
	}

	/**
	 * A failure the plugin tells by itself (no message, no NCM of SquidCore).
	 */
	private static function failure( string $reason ): array {
		return [ 'reason' => $reason, 'message' => '', 'ncm' => '', 'descricao' => '' ];
	}

	private static function notWrittenReason( string $written ): string {
		switch ( $written ) {
			case ProductNcmWriter::KEPT:
				return NcmAiLabels::CHANGED;
			case ProductNcmWriter::GONE:
				return NcmAiLabels::GONE;
		}

		return NcmAiLabels::INVALID;
	}

	private static function filledRow( int $id, array $item, string $source ): array {
		return [
			'productId' => $id,
			'ncm' => NcmCode::normalize( (string) ( $item['ncm'] ?? '' ) ),
			'descricao' => sanitize_text_field( (string) ( $item['descricao'] ?? '' ) ),
			'confianca' => NcmAiLabels::confidence( $item['confianca'] ?? null ),
			'source' => $source,
		];
	}

	private static function failedRow( int $id, string $reason, string $message ): array {
		return [
			'productId' => $id,
			'reason' => $reason,
			'message' => $message,
		];
	}
}
