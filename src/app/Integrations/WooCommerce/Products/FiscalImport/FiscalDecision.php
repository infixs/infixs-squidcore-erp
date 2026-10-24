<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

defined( 'ABSPATH' ) || exit;

/**
 * What the import does with the NCM of one product, and why. Built by FiscalImportPlanner.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
final class FiscalDecision {

	/**
	 * Gets the NCM: it had none, or an invalid one.
	 */
	public const FILL = 'fill';

	/**
	 * Already has the same NCM.
	 */
	public const SAME = 'same';

	/**
	 * Has another valid NCM, kept.
	 */
	public const KEEP = 'keep';

	/**
	 * Has another valid NCM, replaced by choice.
	 */
	public const REPLACE = 'replace';

	/**
	 * Its NCM is only the old Correios Automático one (no SquidCore NCM), replaced or kept by choice.
	 */
	public const HIDDEN_REPLACE = 'hidden_replace';

	public const HIDDEN_KEEP = 'hidden_keep';

	/**
	 * For the store owner to decide (invalid NCM, variations that differ).
	 */
	public const REVIEW = 'review';

	/**
	 * Left out (service, grouped, external, categories that differ).
	 */
	public const SKIP = 'skip';

	/**
	 * No NCM here nor in the other plugin.
	 */
	public const MISSING = 'missing';

	/**
	 * Nothing to bring and a valid NCM already.
	 */
	public const NOTHING = 'nothing';

	/**
	 * Lists of the import screen.
	 */
	public const LIST_CONFLICT = 'conflict';

	public const LIST_HIDDEN = 'hidden';

	public const LIST_REVIEW = 'review';

	public const LIST_SKIPPED = 'skipped';

	public const LIST_MISSING = 'missing';

	private const WRITES = [ self::FILL, self::REPLACE, self::HIDDEN_REPLACE ];

	private const LISTS = [
		self::KEEP => self::LIST_CONFLICT,
		self::REPLACE => self::LIST_CONFLICT,
		self::HIDDEN_REPLACE => self::LIST_HIDDEN,
		self::HIDDEN_KEEP => self::LIST_HIDDEN,
		self::REVIEW => self::LIST_REVIEW,
		self::SKIP => self::LIST_SKIPPED,
		self::MISSING => self::LIST_MISSING,
	];

	/**
	 * @var string
	 */
	private $action;

	/**
	 * @var string
	 */
	private $current;

	/**
	 * @var string
	 */
	private $target;

	/**
	 * @var string|null
	 */
	private $origin;

	/**
	 * @var string
	 */
	private $source;

	/**
	 * @var string|null
	 */
	private $reason;

	/**
	 * @var bool
	 */
	private $fixesInvalid;

	/**
	 * @var bool
	 */
	private $parentOverridden;

	/**
	 * @var array
	 */
	private $variations;

	/**
	 * @var array
	 */
	private $categories;

	/**
	 * @param string      $action           One of the action constants.
	 * @param string      $current          Effective NCM of the product now, digits ('' when none).
	 * @param string      $target           NCM the other plugin uses for it, digits ('' when none or when the
	 *                                      variations differ). Written only when writes().
	 * @param string|null $origin           Where that NCM comes from: product, variations, category or global.
	 * @param string      $source           That NCM as typed in the other plugin.
	 * @param string|null $reason           Why it is reviewed, left out or missing.
	 * @param bool        $fixesInvalid     FILL over an invalid NCM.
	 * @param bool        $parentOverridden Every variation has its own NCM, and it differs from a valid product NCM.
	 * @param array       $variations       REVIEW variations_differ: [{id, name, ncm, origin}].
	 * @param array       $categories       SKIP categories_differ: [{id, name, ncm}].
	 */
	public function __construct( string $action, string $current, string $target = '', ?string $origin = null, string $source = '', ?string $reason = null, bool $fixesInvalid = false, bool $parentOverridden = false, array $variations = [], array $categories = [] ) {
		$this->action = $action;
		$this->current = $current;
		$this->target = $target;
		$this->origin = $origin;
		$this->source = $source;
		$this->reason = $reason;
		$this->fixesInvalid = $fixesInvalid;
		$this->parentOverridden = $parentOverridden;
		$this->variations = $variations;
		$this->categories = $categories;
	}

	public function action(): string {
		return $this->action;
	}

	/**
	 * The import writes target() on the product.
	 */
	public function writes(): bool {
		return in_array( $this->action, self::WRITES, true );
	}

	/**
	 * NCM the other plugin uses for the product, digits only; '' when none.
	 */
	public function target(): string {
		return $this->target;
	}

	/**
	 * Effective NCM of the product before the import, digits only; '' when none.
	 */
	public function current(): string {
		return $this->current;
	}

	/**
	 * product, variations, category or global; null when the other plugin has no NCM for the product.
	 */
	public function origin(): ?string {
		return $this->origin;
	}

	/**
	 * The NCM as typed in the other plugin; '' when none.
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * grouped, external, service, categories_differ, variations_differ, no_source, ncm_length, ncm_filler or
	 * ncm_chapter; null for the other actions.
	 */
	public function reason(): ?string {
		return $this->reason;
	}

	public function fixesInvalid(): bool {
		return $this->fixesInvalid;
	}

	public function parentOverridden(): bool {
		return $this->parentOverridden;
	}

	/**
	 * Variations of a product whose variations differ, with the NCM each one would get (formatted when valid, as
	 * typed otherwise; '' when none) and where it comes from (variation, product, category, global or null).
	 *
	 * @return array<int, array{id: int, name: string, ncm: string, origin: string|null}>
	 */
	public function variations(): array {
		return $this->variations;
	}

	/**
	 * Categories of a product whose categories differ, with their NCM (formatted when valid, as typed otherwise).
	 *
	 * @return array<int, array{id: int, name: string, ncm: string}>
	 */
	public function categories(): array {
		return $this->categories;
	}

	/**
	 * List of the import screen the product goes to (LIST_* constant), null for none.
	 */
	public function listType(): ?string {
		return self::LISTS[ $this->action ] ?? null;
	}
}
