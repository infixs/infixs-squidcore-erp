<?php
/**
 * SquidCore - ERP & CRM Platform
 *
 * This plugin uses AvelPress Framework (https://avelpress.com)
 *
 * @link              https://infixs.com
 * @since             1.0.0
 * @package           Infixs\SquidCore
 *
 * @wordpress-plugin
 * Plugin Name:       		SquidCore - ERP & CRM Platform
 * Plugin URI:        		https://squidcore.com
 * Description:       		Manage your business operations with SquidCore ERP & CRM Platform.
 * Version:           		1.7.0
 * Requires at least: 		6.0
 * Requires PHP:      		7.4
 * Requires Plugins:  		woocommerce
 * WC requires at least:	8.0
 * WC tested up to:      	11.2.1
 * Author:            		Infixs Technology
 * Author URI:        		https://infixs.com
 * Text Domain:       		infixs-squidcore-erp
 * Domain Path:       		/languages
 * License:           		GPLv2 or later
 * License URI:       		http://www.gnu.org/licenses/gpl-2.0.txt
 */

use Infixs\SquidCore\AvelPress\AvelPress;

defined( 'ABSPATH' ) || exit;

//Define globals
define( 'INFIXS_SQUIDCORE_PLUGIN_NAME', 'infixs-squidcore' );
define( 'INFIXS_SQUIDCORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'INFIXS_SQUIDCORE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'INFIXS_SQUIDCORE_BASE_NAME', plugin_basename( __FILE__ ) );
define( 'INFIXS_SQUIDCORE_FILE_NAME', __FILE__ );

require INFIXS_SQUIDCORE_PLUGIN_PATH . 'vendor/autoload.php';

// The app id is not the plugin slug: the stored settings are named after it (see infixs-squidcore.php).
AvelPress::init( 'infixs-squidcore', [
	'base_path' => INFIXS_SQUIDCORE_PLUGIN_PATH . 'src',
] );


function infixs_squidcore_app( $service = null ) {
	return AvelPress::app( $service, 'infixs-squidcore' );
}

/**
 * Version in the header of this file.
 *
 * Use it instead of the app version(), which AvelPress reads from infixs-squidcore.php, a file with no header.
 */
function infixs_squidcore_version(): string {
	static $version = null;

	if ( null === $version ) {
		$version = (string) get_file_data( INFIXS_SQUIDCORE_FILE_NAME, [ 'Version' => 'Version' ] )['Version'];
	}

	return $version;
}

register_activation_hook( __FILE__, 'infixs_squidcore_activate' );

/**
 * Activation of the plugin. A single activation made by a person on the plugins screen asks to open the Import data
 * tab once, when there are NCM codes of another invoice plugin to bring (see FiscalImportAutoOpen). The NCM typed in
 * the Correios Automático field while the plugin was off is brought to the SquidCore field (see CorreiosNcmCopy).
 *
 * @param bool $network_wide Activated for every site of the network.
 */
function infixs_squidcore_activate( $network_wide = false ) {
	\Infixs\SquidCore\Integrations\WooCommerce\Products\CorreiosNcmCopy::restart();
	\Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportAutoOpen::recordActivation(
		(bool) $network_wide,
		! ( defined( 'WP_CLI' ) && WP_CLI ) && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
	);
}
