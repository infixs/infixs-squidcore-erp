<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\AvelPress\Utils\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * What the store owner chose to bring in an import.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
final class FiscalImportChoices {

	/**
	 * Products whose NCM is only the old Correios Automático one (no SquidCore NCM): take the WebmaniaBR one, which is
	 * the one on their invoices, or keep the Correios Automático one.
	 */
	public const HIDDEN_WEBMANIA = 'webmania';

	public const HIDDEN_KEEP = 'keep';

	private const HIDDEN = [ self::HIDDEN_WEBMANIA, self::HIDDEN_KEEP ];

	/**
	 * @var bool
	 */
	private $useCategory;

	/**
	 * @var bool
	 */
	private $useGlobal;

	/**
	 * @var bool
	 */
	private $overwrite;

	/**
	 * @var string
	 */
	private $hidden;

	/**
	 * @param bool   $useCategory Category NCM for products without their own.
	 * @param bool   $useGlobal   Store-wide NCM for the rest.
	 * @param bool   $overwrite   Replace a valid NCM the product already has.
	 * @param string $hidden      HIDDEN_WEBMANIA or HIDDEN_KEEP (anything else keeps).
	 */
	public function __construct( bool $useCategory, bool $useGlobal, bool $overwrite, string $hidden ) {
		$this->useCategory = $useCategory;
		$this->useGlobal = $useGlobal;
		$this->overwrite = $overwrite;
		$this->hidden = in_array( $hidden, self::HIDDEN, true ) ? $hidden : self::HIDDEN_KEEP;
	}

	/**
	 * The category on, the store-wide NCM off, a valid NCM kept. The old Correios Automático NCM is kept while
	 * Correios Automático is active (its field was in use) and replaced otherwise (it is a leftover nobody sees).
	 */
	public static function defaults( bool $correiosActive ): self {
		return new self( true, false, false, $correiosActive ? self::HIDDEN_KEEP : self::HIDDEN_WEBMANIA );
	}

	/**
	 * Choices sent by the store owner; a missing key or an unknown `hidden` takes the default.
	 *
	 * @param array $values {useCategory?, useGlobal?, overwrite?, hidden?}
	 */
	public static function fromArray( array $values, self $defaults ): self {
		$hidden = isset( $values['hidden'] ) && is_string( $values['hidden'] ) && in_array( $values['hidden'], self::HIDDEN, true )
			? $values['hidden']
			: $defaults->hidden();

		return new self(
			array_key_exists( 'useCategory', $values ) ? Sanitizer::boolean( $values['useCategory'] ) : $defaults->useCategory(),
			array_key_exists( 'useGlobal', $values ) ? Sanitizer::boolean( $values['useGlobal'] ) : $defaults->useGlobal(),
			array_key_exists( 'overwrite', $values ) ? Sanitizer::boolean( $values['overwrite'] ) : $defaults->overwrite(),
			$hidden
		);
	}

	public function useCategory(): bool {
		return $this->useCategory;
	}

	public function useGlobal(): bool {
		return $this->useGlobal;
	}

	public function overwrite(): bool {
		return $this->overwrite;
	}

	public function hidden(): string {
		return $this->hidden;
	}

	/**
	 * @return array{useCategory: bool, useGlobal: bool, overwrite: bool, hidden: string}
	 */
	public function toArray(): array {
		return [
			'useCategory' => $this->useCategory,
			'useGlobal' => $this->useGlobal,
			'overwrite' => $this->overwrite,
			'hidden' => $this->hidden,
		];
	}
}
