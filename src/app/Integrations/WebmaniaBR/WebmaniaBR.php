<?php

namespace Infixs\SquidCore\Integrations\WebmaniaBR;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the "Nota Fiscal Eletrônica WooCommerce" plugin of WebmaniaBR and names the data it keeps in the store.
 *
 * The plugin has no uninstall routine, so its data stays in the database after it is deactivated or deleted: the
 * state tells an active plugin from one that left NCM codes behind, one that was only installed or configured, and
 * a store that never had it. Finding the data takes a few `LIMIT 1` queries, cached for 12 hours in the probe.
 *
 * Its data is only ever read, never written, and its API credentials are never read.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class WebmaniaBR {

	/**
	 * Main class of the plugin, loaded while it is active.
	 */
	public const MAIN_CLASS = 'WooCommerceNFe';

	/**
	 * Product metas.
	 */
	public const PRODUCT_NCM_META = '_nfe_codigo_ncm';

	/**
	 * Product type: '2' is a service.
	 */
	public const TYPE_META = '_nfe_tipo_produto';

	/**
	 * '1' leaves the product out of the WebmaniaBR invoices.
	 */
	public const IGNORE_META = '_nfe_ignorar_nfe';

	/**
	 * Variation meta.
	 */
	public const VARIATION_NCM_META = 'variable_ncm';

	/**
	 * Term meta of the product categories.
	 */
	public const CATEGORY_NCM_META = '_ncm';

	/**
	 * Order meta with the history of the documents WebmaniaBR issued for the order.
	 */
	public const ORDER_META = 'nfe';

	/**
	 * Options (WooCommerce › Settings › Nota Fiscal).
	 */
	public const GLOBAL_NCM_OPTION = 'wc_settings_woocommercenfe_ncm';

	/**
	 * '1' or '2' (the order status that triggers it) or 'yes': automatic emission on.
	 */
	public const AUTO_EMISSION_OPTION = 'wc_settings_woocommercenfe_emissao_automatica';

	/**
	 * 'yes': WebmaniaBR asks for CPF/CNPJ at checkout.
	 */
	public const PERSON_TYPE_OPTION = 'wc_settings_woocommercenfe_tipo_pessoa';

	/**
	 * '1' production, '2' homologation.
	 */
	public const ENVIRONMENT_OPTION = 'wc_settings_woocommercenfe_ambiente';

	public const NATURE_OPTION = 'wc_settings_woocommercenfe_natureza_operacao';

	public const ORIGIN_OPTION = 'wc_settings_woocommercenfe_origem';

	/**
	 * Settings screen, relative to the admin URL.
	 */
	public const SETTINGS_URL = 'admin.php?page=wc-settings&tab=woocommercenfe_tab';

	/**
	 * Main class of Brazilian Market on WooCommerce, which asks for CPF/CNPJ at checkout when active.
	 */
	public const BRAZILIAN_MARKET_CLASS = 'Extra_Checkout_Fields_For_Brazil';

	/**
	 * States of the plugin in this store.
	 */
	public const STATE_ACTIVE = 'active';

	public const STATE_INACTIVE_WITH_DATA = 'inactive_with_data';

	public const STATE_INSTALLED_UNUSED = 'installed_unused';

	public const STATE_NONE = 'none';

	public const ENVIRONMENT_PRODUCTION = 'production';

	public const ENVIRONMENT_HOMOLOGATION = 'homologation';

	/**
	 * Cached result of probe().
	 */
	public const PROBE_TRANSIENT = 'infixs_squidcore_fiscal_import_probe';

	public const PROBE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Header of the plugin, to find it installed whatever its folder.
	 */
	private const PLUGIN_NAME = 'Nota Fiscal Eletrônica WooCommerce';

	private const PLUGIN_AUTHOR = 'WebmaniaBR';

	/**
	 * Values of AUTO_EMISSION_OPTION that turn the automatic emission on ('yes' comes from older versions).
	 */
	private const AUTO_EMISSION_ON = [ '1', '2', 'yes' ];

	/**
	 * Values of ENVIRONMENT_OPTION.
	 */
	private const ENVIRONMENTS = [
		'1' => self::ENVIRONMENT_PRODUCTION,
		'2' => self::ENVIRONMENT_HOMOLOGATION,
	];

	/**
	 * Valid ICMS origins (ORIGIN_OPTION keeps 'null' when none was chosen).
	 */
	private const ORIGINS = [ '0', '1', '2', '3', '4', '5', '6', '7', '8' ];

	public function isActive(): bool {
		return class_exists( self::MAIN_CLASS );
	}

	/**
	 * The plugin is active, or its files are in the plugins folder (found by the probe).
	 */
	public function isInstalled(): bool {
		return $this->isActive() || $this->probe()['installed'];
	}

	/**
	 * Version of the installed plugin, null when it is not installed.
	 */
	public function version(): ?string {
		return $this->probe()['version'];
	}

	/**
	 * One of the STATE_* constants:
	 * - active: the plugin is loaded;
	 * - inactive_with_data: not loaded, and NCM codes it kept are still in the store;
	 * - installed_unused: there are signs of it (installed, configured, its product fields saved), but no NCM;
	 * - none: no sign of it.
	 */
	public function state(): string {
		if ( $this->isActive() ) {
			return self::STATE_ACTIVE;
		}

		$probe = $this->probe();

		if ( $probe['found'] ) {
			return self::STATE_INACTIVE_WITH_DATA;
		}

		return $probe['signal'] ? self::STATE_INSTALLED_UNUSED : self::STATE_NONE;
	}

	/**
	 * WebmaniaBR issues the NF-e of the orders by itself.
	 */
	public function autoEmission(): bool {
		return $this->isActive() && in_array( $this->option( self::AUTO_EMISSION_OPTION ), self::AUTO_EMISSION_ON, true );
	}

	/**
	 * WebmaniaBR is the plugin asking for CPF/CNPJ at checkout: it does so only while Brazilian Market is inactive.
	 */
	public function providesCheckoutFields(): bool {
		return $this->isActive()
			&& $this->option( self::PERSON_TYPE_OPTION ) === 'yes'
			&& ! $this->isBrazilianMarketActive();
	}

	/**
	 * Brazilian Market on WooCommerce is loaded (it asks for CPF/CNPJ at checkout itself).
	 */
	protected function isBrazilianMarketActive(): bool {
		return class_exists( self::BRAZILIAN_MARKET_CLASS );
	}

	/**
	 * Environment WebmaniaBR issues in now (ENVIRONMENT_* constant), null when it was never set. The invoices it kept
	 * do not say in which environment they were issued.
	 */
	public function environment(): ?string {
		return self::ENVIRONMENTS[ (string) $this->option( self::ENVIRONMENT_OPTION ) ] ?? null;
	}

	/**
	 * What the cheap checks found, cached for 12 hours (and checked again when the plugin was activated since):
	 * - found: a product NCM it kept; a variation or category NCM counts only with a sign of WebmaniaBR, as those
	 *   keys have generic names another plugin may use;
	 * - invoices: an order keeps a WebmaniaBR invoice history;
	 * - installed and version: the plugin files, whatever their folder;
	 * - signal: any sign of WebmaniaBR (installed, configured, its product fields saved).
	 *
	 * @return array{found: bool, invoices: bool, installed: bool, version: string|null, signal: bool, checkedAt: int}
	 */
	public function probe(): array {
		$cached = get_transient( self::PROBE_TRANSIENT );

		if ( is_array( $cached ) && ( ! empty( $cached['installed'] ) || ! $this->isActive() ) ) {
			return $this->probeShape( $cached );
		}

		$probe = $this->runProbe();
		set_transient( self::PROBE_TRANSIENT, $probe, self::PROBE_TTL );

		return $probe;
	}

	/**
	 * Drops the cached probe: the next probe() looks again.
	 */
	public function forgetProbe(): void {
		delete_transient( self::PROBE_TRANSIENT );
	}

	/**
	 * WooCommerce › Settings › Nota Fiscal.
	 */
	public function settingsUrl(): string {
		return admin_url( self::SETTINGS_URL );
	}

	/**
	 * Default operation nature of the WebmaniaBR invoices, null when unset.
	 */
	public function operationNature(): ?string {
		$nature = sanitize_text_field( (string) $this->option( self::NATURE_OPTION ) );

		return $nature !== '' ? $nature : null;
	}

	/**
	 * Default ICMS origin ('0' to '8') of the WebmaniaBR invoices, null when unset.
	 */
	public function defaultOrigin(): ?string {
		$origin = trim( (string) $this->option( self::ORIGIN_OPTION ) );

		return in_array( $origin, self::ORIGINS, true ) ? $origin : null;
	}

	/**
	 * Store-wide NCM of WebmaniaBR, as typed ('' when unset).
	 */
	public function globalNcm(): string {
		return (string) $this->option( self::GLOBAL_NCM_OPTION );
	}

	/**
	 * @return array{state: string, active: bool, installed: bool, version: string|null, autoEmission: bool, checkoutFields: bool, environment: string|null, hasInvoiceHistory: bool, settingsUrl: string}
	 */
	public function toArray(): array {
		return [
			'state' => $this->state(),
			'active' => $this->isActive(),
			'installed' => $this->isInstalled(),
			'version' => $this->version(),
			'autoEmission' => $this->autoEmission(),
			'checkoutFields' => $this->providesCheckoutFields(),
			'environment' => $this->environment(),
			'hasInvoiceHistory' => $this->probe()['invoices'],
			'settingsUrl' => $this->settingsUrl(),
		];
	}

	/**
	 * @return array{found: bool, invoices: bool, installed: bool, version: string|null, signal: bool, checkedAt: int}
	 */
	private function runProbe(): array {
		$plugin = $this->installedPlugin();
		$installed = $plugin !== null || $this->isActive();
		$signal = $installed
			|| get_option( self::NATURE_OPTION ) !== false
			|| get_option( self::GLOBAL_NCM_OPTION ) !== false
			|| $this->hasProductFields();

		return [
			'found' => $this->hasProductNcm() || ( $signal && ( $this->hasVariationNcm() || $this->hasCategoryNcm() ) ),
			'invoices' => $this->hasInvoices(),
			'installed' => $installed,
			'version' => $plugin !== null && isset( $plugin['Version'] ) && $plugin['Version'] !== '' ? (string) $plugin['Version'] : null,
			'signal' => $signal,
			'checkedAt' => time(),
		];
	}

	/**
	 * @param array $probe Cached probe.
	 *
	 * @return array{found: bool, invoices: bool, installed: bool, version: string|null, signal: bool, checkedAt: int}
	 */
	private function probeShape( array $probe ): array {
		return [
			'found' => ! empty( $probe['found'] ),
			'invoices' => ! empty( $probe['invoices'] ),
			'installed' => ! empty( $probe['installed'] ),
			'version' => isset( $probe['version'] ) && is_string( $probe['version'] ) ? $probe['version'] : null,
			'signal' => ! empty( $probe['signal'] ),
			'checkedAt' => (int) ( $probe['checkedAt'] ?? 0 ),
		];
	}

	/**
	 * Header of the installed plugin (by name first, then by author), null when not installed.
	 */
	private function installedPlugin(): ?array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$by_author = null;

		foreach ( get_plugins() as $plugin ) {
			if ( trim( (string) ( $plugin['Name'] ?? '' ) ) === self::PLUGIN_NAME ) {
				return $plugin;
			}

			if ( $by_author === null && strcasecmp( trim( (string) ( $plugin['Author'] ?? '' ) ), self::PLUGIN_AUTHOR ) === 0 ) {
				$by_author = $plugin;
			}
		}

		return $by_author;
	}

	/**
	 * A product (not trashed) with a meaningful NCM in the WebmaniaBR box: the defaults it saves ('', 'null', 'UN')
	 * have no digit.
	 */
	private function hasProductNcm(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LIMIT 1 probe by meta key, cached for 12 hours.
		return null !== $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND pm.meta_value REGEXP %s AND p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft')
			LIMIT 1",
			self::PRODUCT_NCM_META,
			'[0-9]',
			'product'
		) );
	}

	private function hasVariationNcm(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LIMIT 1 probe by meta key, cached for 12 hours.
		return null !== $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND pm.meta_value REGEXP %s AND p.post_type = %s AND p.post_status <> 'trash'
			LIMIT 1",
			self::VARIATION_NCM_META,
			'[0-9]',
			'product_variation'
		) );
	}

	private function hasCategoryNcm(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LIMIT 1 probe by meta key, cached for 12 hours.
		return null !== $wpdb->get_var( $wpdb->prepare(
			"SELECT tm.term_id FROM {$wpdb->termmeta} tm
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy = %s
			WHERE tm.meta_key = %s AND tm.meta_value REGEXP %s
			LIMIT 1",
			'product_cat',
			self::CATEGORY_NCM_META,
			'[0-9]'
		) );
	}

	/**
	 * The WebmaniaBR box was saved on some product (it always saves the NCM and the product type), whatever the values.
	 */
	private function hasProductFields(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LIMIT 1 probe by meta key, cached for 12 hours.
		return null !== $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) LIMIT 1",
			self::PRODUCT_NCM_META,
			self::TYPE_META
		) );
	}

	/**
	 * Some order keeps a WebmaniaBR invoice history (posts or HPOS storage).
	 */
	private function hasInvoices(): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$ids = wc_get_orders( [
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- LIMIT 1 probe, cached for 12 hours. Both order storages honour meta_key; the posts one ignores meta_query.
			'meta_key' => self::ORDER_META,
			'meta_compare' => 'EXISTS',
			'limit' => 1,
			'return' => 'ids',
		] );

		return is_array( $ids ) && $ids !== [];
	}

	/**
	 * Value of a WebmaniaBR option as text, null when missing or not a scalar. Never used for the credentials.
	 */
	private function option( string $name ): ?string {
		$value = get_option( $name, null );

		return is_scalar( $value ) ? trim( (string) $value ) : null;
	}
}
