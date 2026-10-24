<?php
/**
 * Deleting the plugin: an OAuth connection is ended in SquidCore, the WooCommerce REST API keys the plugin created are
 * removed and the stored credentials go with the settings (see UninstallService). The plugin is not loaded here, so
 * the few classes it needs are built by hand.
 *
 * @package Infixs\SquidCore
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

use Infixs\SquidCore\Integrations\WooCommerce\ActivityRepository;
use Infixs\SquidCore\Integrations\WooCommerce\SyncStatusRepository;
use Infixs\SquidCore\Integrations\WooCommerce\WooCommerceApiKeys;
use Infixs\SquidCore\Services\UninstallService;
use Infixs\SquidCore\Support\Credentials\CredentialProvider;
use Infixs\SquidCore\Support\OAuth\OAuthRevocation;
use Infixs\SquidCore\Support\OAuth\OAuthTokenStore;
use Infixs\SquidCore\Support\SquidCoreClient;

( new UninstallService(
	new OAuthTokenStore(),
	new WooCommerceApiKeys(),
	new OAuthRevocation( function ( string $api_url, CredentialProvider $credential ): SquidCoreClient {
		return new SquidCoreClient( $api_url, $credential, 1 );
	} ),
	new SyncStatusRepository(),
	new ActivityRepository()
) )->run();
