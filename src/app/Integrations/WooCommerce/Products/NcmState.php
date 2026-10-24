<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products;

defined( 'ABSPATH' ) || exit;

/**
 * NCM of one product (simple or parent, no fallback to a parent) as the store keeps it: the NCM that goes on the
 * invoice and the two metas it comes from.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
final class NcmState {

	/**
	 * @var string
	 */
	private $value;

	/**
	 * @var string|null
	 */
	private $own;

	/**
	 * @var string|null
	 */
	private $correios;

	/**
	 * @var bool
	 */
	private $fromCorreios;

	/**
	 * @var bool
	 */
	private $correiosActive;

	/**
	 * @param string      $value          Effective NCM, digits only; '' when none.
	 * @param string|null $own            SquidCore meta as stored, null when it does not exist.
	 * @param string|null $correios       Correios Automático meta as stored, null when it does not exist.
	 * @param bool        $fromCorreios   The effective NCM comes from the Correios Automático meta (no SquidCore meta).
	 * @param bool        $correiosActive Correios Automático is active.
	 */
	public function __construct( string $value, ?string $own, ?string $correios, bool $fromCorreios, bool $correiosActive ) {
		$this->value = $value;
		$this->own = $own;
		$this->correios = $correios;
		$this->fromCorreios = $fromCorreios;
		$this->correiosActive = $correiosActive;
	}

	/**
	 * Effective NCM, digits only; '' when none.
	 */
	public function value(): string {
		return $this->value;
	}

	public function own(): ?string {
		return $this->own;
	}

	public function correios(): ?string {
		return $this->correios;
	}

	public function fromCorreios(): bool {
		return $this->fromCorreios;
	}

	public function correiosActive(): bool {
		return $this->correiosActive;
	}

	public function ownExists(): bool {
		return $this->own !== null;
	}

	public function isValid(): bool {
		return NcmCode::isValid( $this->value );
	}
}
