<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * The SEFAZ rejections the store owner can fix on their own, read from the status (cStat) and the message SquidCore
 * keeps of the invoice.
 *
 * The usual case is a store that issued NF-e in another system before SquidCore: its first NF-e reuses a number of
 * that system (539, the message carries the access key of the NF-e SEFAZ already has) or a number it voided (206).
 * The message is read when the status is missing or unknown, without trusting it: an access key is only taken when
 * its check digit and model hold.
 *
 * @package Infixs\SquidCore
 * @since   1.5.0
 */
class SefazRejection {

	/**
	 * Another NF-e with the same number and series is authorized (cStat 539).
	 */
	public const NUMBER_USED = 'number_used';

	/**
	 * The number was voided (inutilizado) at SEFAZ (cStat 206).
	 */
	public const NUMBER_VOIDED = 'number_voided';

	/**
	 * SEFAZ already has this very NF-e, e.g. from an attempt whose answer was lost (cStat 204).
	 */
	public const ALREADY_RECEIVED = 'already_received';

	/**
	 * Kinds fixed in the NF-e numbering of SquidCore.
	 */
	public const NUMBERING_KINDS = [ self::NUMBER_USED, self::NUMBER_VOIDED ];

	private const KINDS_BY_STATUS = [
		'539' => self::NUMBER_USED,
		'206' => self::NUMBER_VOIDED,
		'204' => self::ALREADY_RECEIVED,
	];

	/**
	 * NF-e (55) and NFC-e (65).
	 */
	private const MODELS = [ '55', '65' ];

	/**
	 * @var string
	 */
	private $kind;

	/**
	 * @var array{series: int, number: int, year: int, month: int}|null
	 */
	private $existing;

	/**
	 * @param array{series: int, number: int, year: int, month: int}|null $existing
	 */
	private function __construct( string $kind, ?array $existing ) {
		$this->kind = $kind;
		$this->existing = $existing;
	}

	/**
	 * @param string $status  cStat of the SEFAZ answer, blank when SquidCore has none.
	 * @param string $message Message of the SEFAZ answer.
	 *
	 * @return self|null Null when the rejection is not one of the known kinds.
	 */
	public static function classify( string $status, string $message ): ?self {
		$kind = self::kindOf( $status, $message );

		if ( $kind === null ) {
			return null;
		}

		return new self( $kind, $kind === self::NUMBER_USED ? self::parseKey( $message ) : null );
	}

	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Whether the store owner fixes it in the NF-e numbering of SquidCore.
	 */
	public function isNumbering(): bool {
		return in_array( $this->kind, self::NUMBERING_KINDS, true );
	}

	/**
	 * The NF-e SEFAZ already has with this number, read from its access key in the message.
	 *
	 * @return array{series: int, number: int, year: int, month: int}|null Null when the message carries no valid key.
	 */
	public function existingInvoice(): ?array {
		return $this->existing;
	}

	/**
	 * The status decides; the message only when the status is blank or another one. A message without the status
	 * often starts with it ("Rejeição 539: ...").
	 */
	private static function kindOf( string $status, string $message ): ?string {
		$status = (string) preg_replace( '/\D+/', '', $status );

		if ( isset( self::KINDS_BY_STATUS[ $status ] ) ) {
			return self::KINDS_BY_STATUS[ $status ];
		}

		$text = strtolower( remove_accents( $message ) );

		if ( $status === '' && preg_match( '/(?:rejeicao|cstat)\s*:?\s*(\d{3})\b|^\s*(\d{3})\s*[-:]/', $text, $matches ) ) {
			$code = $matches[1] !== '' ? $matches[1] : ( $matches[2] ?? '' );

			if ( isset( self::KINDS_BY_STATUS[ $code ] ) ) {
				return self::KINDS_BY_STATUS[ $code ];
			}
		}

		// SEFAZ writes it with and without the comma: "Duplicidade de NF-e, com diferença na Chave de Acesso".
		if ( preg_match( '/duplicidade de nfc?-?e\s*,?\s*com diferenca na chave/', $text ) ) {
			return self::NUMBER_USED;
		}

		if ( strpos( $text, 'inutilizad' ) !== false ) {
			return self::NUMBER_VOIDED;
		}

		if ( preg_match( '/duplicidade de nfc?-?e/', $text ) ) {
			return self::ALREADY_RECEIVED;
		}

		return null;
	}

	/**
	 * Access key of the message ("[chNFe:...]" first, otherwise any 44-digit run), split into its parts. Positions:
	 * cUF 1-2, AAMM 3-6, CNPJ 7-20, model 21-22, series 23-25, number 26-34, emission type 35, code 36-43, check digit 44.
	 *
	 * @return array{series: int, number: int, year: int, month: int}|null
	 */
	private static function parseKey( string $message ): ?array {
		$candidates = [];

		if ( preg_match( '/chNFe\s*[:=]?\s*(\d{44})(?!\d)/i', $message, $matches ) ) {
			$candidates[] = $matches[1];
		}

		if ( preg_match_all( '/(?<!\d)\d{44}(?!\d)/', $message, $matches ) ) {
			$candidates = array_merge( $candidates, $matches[0] );
		}

		foreach ( $candidates as $key ) {
			$parts = self::keyParts( $key );

			if ( $parts !== null ) {
				return $parts;
			}
		}

		return null;
	}

	/**
	 * @return array{series: int, number: int, year: int, month: int}|null Null when the key is not plausible.
	 */
	private static function keyParts( string $key ): ?array {
		$month = (int) substr( $key, 4, 2 );
		$number = (int) substr( $key, 25, 9 );

		if ( ! in_array( substr( $key, 20, 2 ), self::MODELS, true ) || $month < 1 || $month > 12 || $number < 1 || ! self::hasValidCheckDigit( $key ) ) {
			return null;
		}

		return [
			'series' => (int) substr( $key, 22, 3 ),
			'number' => $number,
			'year' => 2000 + (int) substr( $key, 2, 2 ),
			'month' => $month,
		];
	}

	/**
	 * Mod-11 check digit over the first 43 digits, weights 2 to 9 from the right.
	 */
	private static function hasValidCheckDigit( string $key ): bool {
		$sum = 0;
		$weight = 2;

		for ( $i = 42; $i >= 0; $i-- ) {
			$sum += (int) $key[ $i ] * $weight;
			$weight = $weight === 9 ? 2 : $weight + 1;
		}

		$rest = $sum % 11;

		return ( $rest < 2 ? 0 : 11 - $rest ) === (int) $key[43];
	}
}
