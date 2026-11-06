<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products;

use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosAutomatico;
use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaBR;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmAi\NcmAiAvailability;

defined( 'ABSPATH' ) || exit;

/**
 * NCM field in the Shipping tab of the product editor: the single NCM field of the store.
 *
 * - It is always shown. While Correios Automático is active, its own NCM input is hidden and receives a copy of this
 *   one after every save (ProductNcmStore::syncAfterSave()), so its international shipments keep working.
 * - A product that never had this field shows the Correios Automático NCM, and keeps it once saved.
 * - A form opened before the NCM changed elsewhere (an import, for example) does not put the old value back: the
 *   field is saved only when the store owner changed it.
 * - With WebmaniaBR active, it says which NCM counts and offers the one WebmaniaBR has for the product.
 * - While the organization has an AI in SquidCore, an empty field offers "Suggest with AI": the suggestion goes into
 *   the field, with its description and confidence below, and is saved only with the product.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class ProductNcmField {

	/**
	 * Hidden field with the value the NCM field showed when the form was built.
	 */
	public const LOADED_FIELD = '_infixs_squidcore_ncm_loaded';

	public const SCRIPT_HANDLE = 'infixs-squidcore-product-ncm';

	/**
	 * Runs after the save of Correios Automático (priority 10), which writes its NCM meta from its own input.
	 */
	public const AFTER_SAVE_PRIORITY = 99;

	/**
	 * Runs before every other save of the product editor (WooCommerce and Correios Automático save at 10).
	 */
	public const BEFORE_SAVE_PRIORITY = 1;

	/**
	 * Before the fields of Correios Automático, where its (hidden) NCM field was.
	 */
	private const RENDER_PRIORITY = 9;

	/**
	 * Hides the NCM input of Correios Automático (its other fields share the same render callback).
	 */
	private const CORREIOS_FIELD_CSS = '.form-field._infixs_correios_automatico_ncm_field{display:none}';

	/**
	 * Style the CSS goes with: registered by WooCommerce on the product screen.
	 */
	private const WOOCOMMERCE_STYLE = 'woocommerce_admin_styles';

	/**
	 * @var ProductNcmStore
	 */
	protected $store;

	/**
	 * @var CorreiosAutomatico
	 */
	protected $correios;

	/**
	 * @var WebmaniaBR
	 */
	protected $webmania;

	/**
	 * @var NcmAiAvailability|null
	 */
	protected $ai;

	/**
	 * Products whose NCM field the store owner changed in this request: their NCM wins in the sync after the save.
	 *
	 * @var array<int, true>
	 */
	private $edited = [];

	/**
	 * @param NcmAiAvailability|null $ai Whether the AI of SquidCore can suggest the NCM; null never offers it.
	 */
	public function __construct( ProductNcmStore $store, CorreiosAutomatico $correios, WebmaniaBR $webmania, ?NcmAiAvailability $ai = null ) {
		$this->store = $store;
		$this->correios = $correios;
		$this->webmania = $webmania;
		$this->ai = $ai;
	}

	public function register(): void {
		add_action( 'woocommerce_product_options_shipping', [ $this, 'render' ], self::RENDER_PRIORITY );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'beforeSave' ], self::BEFORE_SAVE_PRIORITY );
		add_action( 'woocommerce_process_product_meta', [ $this, 'afterSave' ], self::AFTER_SAVE_PRIORITY );
		// After WooCommerce registers its admin styles (priority 10, hooked later than this one).
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ], 20 );
	}

	public function render(): void {
		global $product_object;

		if ( ! $product_object instanceof \WC_Product ) {
			return;
		}

		$value = $this->store->displayValue( $product_object );

		echo '<div class="options_group">';

		woocommerce_wp_text_input( [
			'id' => ProductNcmStore::META_KEY,
			'label' => __( 'NCM', 'infixs-squidcore-erp' ),
			'placeholder' => '00000000',
			'desc_tip' => true,
			'description' => $this->correios->isActive()
				? __( 'Used on the SquidCore NF-e and on the international shipments of Correios Automático.', 'infixs-squidcore-erp' )
				: __( 'Mercosur product classification (8 digits), used by SquidCore on the invoice (NF-e).', 'infixs-squidcore-erp' ),
			'value' => $value,
			'custom_attributes' => [
				'inputmode' => 'numeric',
				'maxlength' => '10',
			],
		] );

		printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( self::LOADED_FIELD ), esc_attr( $value ) );

		if ( $this->webmania->isActive() ) {
			$this->renderWebmaniaHint( $product_object );
		}

		if ( $this->offersAi() ) {
			$this->renderAiSuggestion( $product_object, $value );
		}

		echo '</div>';
	}

	/**
	 * Runs inside the product save of WooCommerce, which already checked the nonce and the capability.
	 */
	public function save( \WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by WooCommerce before this hook.
		if ( ! isset( $_POST[ ProductNcmStore::META_KEY ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by WooCommerce before this hook.
		$posted = NcmCode::normalize( sanitize_text_field( wp_unslash( $_POST[ ProductNcmStore::META_KEY ] ) ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by WooCommerce before this hook.
		if ( isset( $_POST[ self::LOADED_FIELD ] ) && NcmCode::normalize( sanitize_text_field( wp_unslash( $_POST[ self::LOADED_FIELD ] ) ) ) === $posted ) {
			return;
		}

		$this->store->writeOwn( $product, $posted );
		$this->edited[ $product->get_id() ] = true;

		if ( $posted !== '' && ! NcmCode::isValid( $posted ) && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			\WC_Admin_Meta_Boxes::add_error( __( 'Check the NCM: it has 8 digits and must exist in the official table to go on the NF-e.', 'infixs-squidcore-erp' ) );
		}
	}

	/**
	 * Runs before the product editor saves anything: the NCM the store had is noted (the hidden Correios input may post
	 * an older one).
	 *
	 * @param int $postId
	 */
	public function beforeSave( $postId ): void {
		$this->store->beginEditorSave( (int) $postId );
	}

	/**
	 * Runs after every save of the product editor, Correios Automático included.
	 *
	 * @param int $postId
	 */
	public function afterSave( $postId ): void {
		$this->store->syncAfterSave( (int) $postId, isset( $this->edited[ (int) $postId ] ) );
	}

	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || $screen->id !== 'product' ) {
			return;
		}

		if ( $this->correios->isActive() ) {
			$this->addInlineStyle( self::CORREIOS_FIELD_CSS );
		}

		$ai = $this->offersAi();

		if ( ! $ai && ! $this->webmania->isActive() ) {
			return;
		}

		$path = 'assets/admin/js/product-ncm.js';

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			\INFIXS_SQUIDCORE_PLUGIN_URL . $path,
			$ai ? [ 'wp-api-fetch' ] : [],
			(string) filemtime( \INFIXS_SQUIDCORE_PLUGIN_PATH . $path ),
			true
		);

		if ( $ai ) {
			wp_localize_script( self::SCRIPT_HANDLE, 'infixsSquidCoreProductNcm', [
				'restPath' => '/infixs-squidcore/v1/products/',
				'i18n' => [
					'asking' => __( 'Asking the AI of SquidCore…', 'infixs-squidcore-erp' ),
					'error' => __( 'The NCM could not be suggested. Try again.', 'infixs-squidcore-erp' ),
					/* translators: %s: NCM suggested by the AI with its description, e.g. "3406.00.00 — Velas, pavios e semelhantes". */
					'kept' => __( 'The AI suggested %s. The NCM in the field was filled in while it answered, so it was kept.', 'infixs-squidcore-erp' ),
					/* translators: %s: NCM SquidCore uses on the invoices of the product, e.g. 6109.10.00. */
					'keptSquidcore' => __( 'SquidCore already uses the NCM %s on the invoices of this product. The NCM in the field was filled in while the AI answered, so it was kept.', 'infixs-squidcore-erp' ),
				],
			] );
		}
	}

	/**
	 * "Suggest with AI" is offered while the organization has an AI in SquidCore and the user edits products.
	 */
	public function offersAi(): bool {
		return $this->ai !== null && current_user_can( 'edit_products' ) && $this->ai->isAvailable();
	}

	/**
	 * Says which NCM counts and, when WebmaniaBR has a valid NCM for the product that differs from this one, offers it.
	 */
	private function renderWebmaniaHint( \WC_Product $product ): void {
		$suggestion = NcmCode::normalize( (string) $product->get_meta( WebmaniaBR::PRODUCT_NCM_META, true, 'edit' ) );

		echo '<p class="form-field infixs-squidcore-ncm-hint"><span class="description">';
		esc_html_e( 'SquidCore uses this NCM. The NCM code in the Informações Fiscais box is only for WebmaniaBR invoices.', 'infixs-squidcore-erp' );

		if ( NcmCode::isValid( $suggestion ) && $suggestion !== $this->store->effective( $product ) ) {
			echo '<br />';
			/* translators: %s: NCM code, e.g. 6109.10.00. */
			echo esc_html( sprintf( __( 'In WebmaniaBR: %s', 'infixs-squidcore-erp' ), NcmCode::format( $suggestion ) ) );
			echo ' &middot; ';
			printf(
				'<button type="button" class="button-link" data-isq-use-ncm="%s" aria-label="%s">%s</button>',
				esc_attr( $suggestion ),
				/* translators: %s: NCM code, e.g. 6109.10.00. */
				esc_attr( sprintf( __( 'Use the NCM %s from WebmaniaBR', 'infixs-squidcore-erp' ), NcmCode::format( $suggestion ) ) ),
				esc_html__( 'Use this', 'infixs-squidcore-erp' )
			);
		}

		echo '</span></p>';
	}

	/**
	 * The "Suggest with AI" button, shown while the field is empty (the script hides it as soon as something is typed),
	 * and the line that tells the suggestion or why there is none.
	 *
	 * @param string $value What the field shows.
	 */
	private function renderAiSuggestion( \WC_Product $product, string $value ): void {
		printf(
			'<p class="form-field infixs-squidcore-ncm-ai" data-isq-ncm-ai%s><span class="description"><button type="button" class="button" data-isq-ncm-suggest="%d">%s</button><span class="spinner" data-isq-ncm-spinner style="float:none;margin:0 6px;"></span></span></p>',
			NcmCode::normalize( $value ) !== '' ? ' style="display:none;"' : '',
			(int) $product->get_id(),
			esc_html__( 'Suggest with AI', 'infixs-squidcore-erp' )
		);

		echo '<p class="form-field infixs-squidcore-ncm-ai-result" data-isq-ncm-result style="display:none;"><span class="description" role="status" aria-live="polite"></span></p>';
	}

	/**
	 * Adds CSS to the WooCommerce admin styles of the screen, or to a style of its own when they are missing.
	 */
	private function addInlineStyle( string $css ): void {
		$handle = self::WOOCOMMERCE_STYLE;

		if ( ! wp_style_is( $handle, 'registered' ) ) {
			$handle = self::SCRIPT_HANDLE;
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- no file, only the inline CSS.
			wp_register_style( $handle, false );
			wp_enqueue_style( $handle );
		}

		wp_add_inline_style( $handle, $css );
	}
}
