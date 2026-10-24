<?php

defined( 'ABSPATH' ) || exit;

return [
	// AvelPress would use the app id, which is not the plugin slug nor the main file name: the plugin declares the
	// WooCommerce feature compatibility itself (FeatureCompatibility), and WordPress loads the WordPress.org language
	// packs of the infixs-squidcore-erp text domain on its own.
	'woocommerce_declare_compatibility' => false,
	'enable_translation' => false,

	// Admin Classes
	'admin_menu_class' => \Infixs\SquidCore\Admin\Menu::class,
	'admin_setup_class' => \Infixs\SquidCore\Admin\Setup::class,

	'api_base_url' => 'https://api.squidcore.com',
	'app_url' => 'https://app.squidcore.com',

	// "Conectar com SquidCore": the official public OAuth client "SquidCore para WooCommerce", the same id in every
	// SquidCore environment (INFIXS_SQUIDCORE_OAUTH_CLIENT_ID in wp-config.php overrides it for development), and the
	// scopes of the plugin token plus `profile` (GET /v1/me tells the organization).
	'oauth_client_id' => 'oacli_000000000000000W00C0MMERCE',
	'oauth_scopes' => 'profile products sales_orders customers tax_invoice.read tax_invoice.write tax_invoice.cancel',
	'support_whatsapp' => '+5541987833020',
];
