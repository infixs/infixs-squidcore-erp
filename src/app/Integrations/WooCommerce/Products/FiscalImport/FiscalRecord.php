<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

defined( 'ABSPATH' ) || exit;

/**
 * Fiscal data another invoice plugin kept for one parent product, as typed there: what the planner needs to tell
 * which NCM that plugin put on the invoices of the product.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
final class FiscalRecord {

	/**
	 * @var int
	 */
	private $productId;

	/**
	 * @var string
	 */
	private $type;

	/**
	 * @var string
	 */
	private $name;

	/**
	 * @var bool
	 */
	private $isVirtual;

	/**
	 * @var string
	 */
	private $productNcm;

	/**
	 * @var array<int, array{name: string, ncm: string}>
	 */
	private $variations;

	/**
	 * @var array<int, array{name: string, ncm: string}>
	 */
	private $categories;

	/**
	 * @var bool
	 */
	private $isService;

	/**
	 * @var bool
	 */
	private $ignoredInWebmania;

	/**
	 * @param int    $productId         Parent product id.
	 * @param string $type              WooCommerce product type (simple, variable, grouped, external...).
	 * @param string $name              Product name.
	 * @param bool   $isVirtual         Virtual product (no Shipping tab).
	 * @param string $productNcm        NCM of the product, as typed ('' when none).
	 * @param array  $variations        Variation id => {name, ncm as typed ('' when none)}, in menu order.
	 * @param array  $categories        Term id => {name, ncm as typed}: the direct categories with an NCM, in the
	 *                                  order WordPress lists them.
	 * @param bool   $isService         The plugin issued a service invoice (NFS-e) for it.
	 * @param bool   $ignoredInWebmania The plugin left it out of its invoices.
	 */
	public function __construct( int $productId, string $type, string $name, bool $isVirtual, string $productNcm, array $variations, array $categories, bool $isService, bool $ignoredInWebmania ) {
		$this->productId = $productId;
		$this->type = $type;
		$this->name = $name;
		$this->isVirtual = $isVirtual;
		$this->productNcm = $productNcm;
		$this->variations = self::entries( $variations );
		$this->categories = self::entries( $categories );
		$this->isService = $isService;
		$this->ignoredInWebmania = $ignoredInWebmania;
	}

	public function productId(): int {
		return $this->productId;
	}

	public function type(): string {
		return $this->type;
	}

	public function name(): string {
		return $this->name;
	}

	public function isVirtual(): bool {
		return $this->isVirtual;
	}

	/**
	 * NCM of the product, as typed ('' when none).
	 */
	public function productNcm(): string {
		return $this->productNcm;
	}

	/**
	 * @return array<int, array{name: string, ncm: string}> Variation id => name and NCM as typed, in menu order.
	 */
	public function variations(): array {
		return $this->variations;
	}

	/**
	 * @return array<int, array{name: string, ncm: string}> Term id => name and NCM as typed, direct categories with an
	 *                                                       NCM only.
	 */
	public function categories(): array {
		return $this->categories;
	}

	public function isService(): bool {
		return $this->isService;
	}

	public function ignoredInWebmania(): bool {
		return $this->ignoredInWebmania;
	}

	/**
	 * @param array $entries Id => {name, ncm}.
	 *
	 * @return array<int, array{name: string, ncm: string}>
	 */
	private static function entries( array $entries ): array {
		$clean = [];

		foreach ( $entries as $id => $entry ) {
			$clean[ (int) $id ] = [
				'name' => is_array( $entry ) && isset( $entry['name'] ) && is_scalar( $entry['name'] ) ? (string) $entry['name'] : '',
				'ncm' => is_array( $entry ) && isset( $entry['ncm'] ) && is_scalar( $entry['ncm'] ) ? (string) $entry['ncm'] : '',
			];
		}

		return $clean;
	}
}
