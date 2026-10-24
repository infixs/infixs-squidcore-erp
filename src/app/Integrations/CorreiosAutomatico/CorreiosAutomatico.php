<?php

namespace Infixs\SquidCore\Integrations\CorreiosAutomatico;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the Correios Automático plugins and what they support.
 *
 * Features are detected by the classes that implement them, never by version numbers, so the plugin keeps
 * working with any release of Correios Automático (or without it).
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class CorreiosAutomatico {

	/**
	 * Service of the PRO plugin that holds the automatic pre-posting until the invoice is ready
	 * (filter `infixs_correios_automatico_auto_prepost_wait_invoice`).
	 */
	private const WAIT_INVOICE_CLASS = 'Infixs\\CorreiosAutomaticoPro\\Services\\AutoPrepostInvoice';

	/**
	 * Service of the free plugin that takes the simplified DANFE of an invoice provider (filter
	 * `infixs_correios_automatico_order_danfe`) into the labels.
	 */
	private const DANFE_RESOLVER_CLASS = 'Infixs\\CorreiosAutomatico\\Services\\OrderDanfeResolver';

	/**
	 * Address of the print page of the free plugin, which prints only the simplified DANFE with `view=danfe`.
	 */
	private const PRINT_URL_CLASS = 'Infixs\\CorreiosAutomatico\\Core\\Admin\\PrintUrl';

	/**
	 * Capability of the print page of Correios Automático.
	 */
	public const PRINT_CAPABILITY = 'manage_woocommerce';

	public function isActive(): bool {
		return defined( 'INFIXS_CORREIOS_AUTOMATICO_PLUGIN_VERSION' );
	}

	public function isProActive(): bool {
		return defined( 'INFIXS_CORREIOS_AUTOMATICO_PRO_PLUGIN_VERSION' );
	}

	/**
	 * Whether the automatic pre-posting can wait for the invoice.
	 */
	public function supportsWait(): bool {
		return $this->isProActive() && method_exists( self::WAIT_INVOICE_CLASS, 'shouldWait' );
	}

	/**
	 * Whether Correios Automático prints the simplified DANFE (DANFE Simplificado - Etiqueta) with its labels and
	 * in a print of its own.
	 */
	public function supportsDanfe(): bool {
		return $this->isActive() && method_exists( self::DANFE_RESOLVER_CLASS, 'resolve' ) && method_exists( self::PRINT_URL_CLASS, 'orders' );
	}

	/**
	 * Whether the current user may open the simplified DANFE print of Correios Automático.
	 */
	public function canPrintDanfe(): bool {
		return $this->supportsDanfe() && current_user_can( self::PRINT_CAPABILITY );
	}

	/**
	 * Print page of Correios Automático with only the simplified DANFE of the orders; blank when it cannot be opened.
	 *
	 * @param int[] $orderIds
	 */
	public function danfePrintUrl( array $orderIds ): string {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $orderIds ) ) ) );

		if ( ! $ids || ! $this->canPrintDanfe() ) {
			return '';
		}

		$url = call_user_func( [ self::PRINT_URL_CLASS, 'orders' ], $ids, 'danfe' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * @return array{active: bool, proActive: bool, supportsWait: bool, supportsDanfe: bool}
	 */
	public function toArray(): array {
		return [
			'active' => $this->isActive(),
			'proActive' => $this->isProActive(),
			'supportsWait' => $this->supportsWait(),
			'supportsDanfe' => $this->supportsDanfe(),
		];
	}
}
