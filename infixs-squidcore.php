<?php
/**
 * File of the 'infixs-squidcore' AvelPress app. The plugin itself is infixs-squidcore-erp.php.
 *
 * The plugin slug is infixs-squidcore-erp, but the AvelPress app id stays 'infixs-squidcore': the stored settings
 * (infixs_squidcore_settings) and the migrations table are named after it. AvelPress::init() refuses to start
 * unless a file named after the app id exists in the plugin root, and this file is only there for that check.
 *
 * AvelPress also reads the version and declares the WooCommerce feature compatibility from this file, so the
 * plugin does both from the main file instead: infixs_squidcore_version() and FeatureCompatibility. Its text domain
 * loader would use the app id too, so it stays off: WordPress loads the WordPress.org language packs of the
 * infixs-squidcore-erp text domain on its own.
 *
 * It has no plugin header, so WordPress does not list it, and loading it does nothing.
 *
 * @package Infixs\SquidCore
 */

defined( 'ABSPATH' ) || exit;
