<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

use Infixs\SquidCore\AvelPress\Config\SettingsRepository;
use Infixs\SquidCore\AvelPress\Utils\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Invoice (NF-e) options, kept under `invoices` in the plugin settings. Defaults live here.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class InvoiceSettings {

	/**
	 * Hours the automatic emission may wait after the payment.
	 */
	public const DELAY_HOURS = [ 0, 1, 2, 4, 6, 12, 24 ];

	/**
	 * @var SettingsRepository
	 */
	protected $settings;

	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether paid orders get their invoice issued automatically.
	 */
	public function isEnabled(): bool {
		return $this->settings->boolean( 'invoices.enabled', false );
	}

	public function delayHours(): int {
		$hours = $this->settings->integer( 'invoices.delay_hours', 0 );

		return in_array( $hours, self::DELAY_HOURS, true ) ? $hours : 0;
	}

	/**
	 * Whether the completed order email links the DANFE and the XML.
	 */
	public function linksInCustomerEmail(): bool {
		return $this->settings->boolean( 'invoices.customer_email', true );
	}

	/**
	 * Whether a missing NCM is filled by the AI of SquidCore before giving up on the automatic emission.
	 */
	public function autoFillNcmWithAi(): bool {
		return $this->settings->boolean( 'invoices.auto_fill_ncm_ai', false );
	}

	/**
	 * Whether the automatic pre-posting of Correios Automático waits for the invoice.
	 */
	public function correiosWait(): bool {
		return $this->settings->boolean( 'invoices.correios_wait', true );
	}

	/**
	 * @return array{enabled: bool, delayHours: int, customerEmail: bool, autoFillNcmWithAi: bool, correiosWait: bool}
	 */
	public function toArray(): array {
		return [
			'enabled' => $this->isEnabled(),
			'delayHours' => $this->delayHours(),
			'customerEmail' => $this->linksInCustomerEmail(),
			'autoFillNcmWithAi' => $this->autoFillNcmWithAi(),
			'correiosWait' => $this->correiosWait(),
		];
	}

	/**
	 * @param array $values Same keys as toArray(); a missing key keeps its value.
	 *
	 * @return true|\WP_Error
	 */
	public function save( array $values ) {
		$current = $this->toArray();
		$delay = array_key_exists( 'delayHours', $values ) ? $values['delayHours'] : $current['delayHours'];

		if ( ! is_numeric( $delay ) || ! in_array( (int) $delay, self::DELAY_HOURS, true ) ) {
			return new \WP_Error(
				'invalid_delay_hours',
				/* translators: %s: allowed values, e.g. "0, 1, 2". */
				sprintf( __( 'Choose one of these delays (hours): %s.', 'infixs-squidcore-erp' ), implode( ', ', self::DELAY_HOURS ) ),
				[ 'status' => 400 ]
			);
		}

		$this->settings->update( 'invoices', [
			'enabled' => $this->flag( $values, 'enabled', $current['enabled'] ),
			'delay_hours' => (int) $delay,
			'customer_email' => $this->flag( $values, 'customerEmail', $current['customerEmail'] ),
			'auto_fill_ncm_ai' => $this->flag( $values, 'autoFillNcmWithAi', $current['autoFillNcmWithAi'] ),
			'correios_wait' => $this->flag( $values, 'correiosWait', $current['correiosWait'] ),
		] );

		return true;
	}

	private function flag( array $values, string $key, bool $default ): bool {
		if ( ! array_key_exists( $key, $values ) ) {
			return $default;
		}

		return Sanitizer::boolean( $values[ $key ] );
	}
}
