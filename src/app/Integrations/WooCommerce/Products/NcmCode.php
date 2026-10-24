<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products;

defined( 'ABSPATH' ) || exit;

/**
 * NCM code (Mercosur product classification): how it is stored, when it can go on an NF-e and how it is shown.
 *
 * The check is structural (8 digits, no filler, an existing chapter); it does not look the code up in the official
 * table.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
final class NcmCode {

	public const LENGTH = 8;

	/**
	 * Problems found by problem().
	 */
	public const PROBLEM_EMPTY = 'empty';

	public const PROBLEM_LENGTH = 'length';

	public const PROBLEM_FILLER = 'filler';

	public const PROBLEM_CHAPTER = 'chapter';

	/**
	 * Chapter of the NCM table that does not exist (reserved).
	 */
	private const RESERVED_CHAPTER = 77;

	private const LAST_CHAPTER = 97;

	/**
	 * Digits only: "6109.10.00" gives "61091000"; text without digits ("null", "UN") gives ''.
	 */
	public static function normalize( string $raw ): string {
		return (string) preg_replace( '/\D+/', '', $raw );
	}

	/**
	 * Why the digits cannot go on an NF-e, null when they can.
	 *
	 * @param string $digits Output of normalize().
	 *
	 * @return string|null One of the PROBLEM_* constants.
	 */
	public static function problem( string $digits ): ?string {
		if ( $digits === '' ) {
			return self::PROBLEM_EMPTY;
		}

		if ( strlen( $digits ) !== self::LENGTH ) {
			return self::PROBLEM_LENGTH;
		}

		if ( $digits === '00000000' ) {
			return self::PROBLEM_FILLER;
		}

		$chapter = (int) substr( $digits, 0, 2 );

		if ( $chapter < 1 || $chapter > self::LAST_CHAPTER || $chapter === self::RESERVED_CHAPTER ) {
			return self::PROBLEM_CHAPTER;
		}

		return null;
	}

	/**
	 * @param string $digits Output of normalize().
	 */
	public static function isValid( string $digits ): bool {
		return self::problem( $digits ) === null;
	}

	/**
	 * "61091000" gives "6109.10.00"; anything that is not 8 digits comes back unchanged.
	 */
	public static function format( string $digits ): string {
		if ( ! preg_match( '/^\d{8}$/', $digits ) ) {
			return $digits;
		}

		return substr( $digits, 0, 4 ) . '.' . substr( $digits, 4, 2 ) . '.' . substr( $digits, 6, 2 );
	}
}
