<?php

namespace Infixs\SquidCore\Integrations\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the WooCommerce features the plugin supports (HPOS and the product block editor).
 *
 * AvelPress would declare them for {app id}.php, which is not the plugin main file, so
 * `app.woocommerce_declare_compatibility` is off and the plugin declares them for its own file.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class FeatureCompatibility {

	public const FEATURES = [ 'custom_order_tables', 'product_block_editor' ];

	/**
	 * @var string Absolute path of the plugin main file.
	 */
	protected $pluginFile;

	public function __construct( string $pluginFile ) {
		$this->pluginFile = $pluginFile;
	}

	public function register(): void {
		add_action( 'before_woocommerce_init', [ $this, 'declareCompatibility' ] );
	}

	public function declareCompatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		foreach ( self::FEATURES as $feature ) {
			FeaturesUtil::declare_compatibility( $feature, $this->pluginFile, true );
		}
	}
}
