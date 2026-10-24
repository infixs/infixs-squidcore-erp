<?php

namespace Infixs\SquidCore\Providers;

defined( 'ABSPATH' ) || exit;

use Infixs\SquidCore\AvelPress\Support\ServiceProvider;
use Infixs\SquidCore\Admin\OAuthCallback;
use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosAutomatico;
use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosInvoiceBridge;
use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaBR;
use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaFiscalSource;
use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaInvoiceBridge;
use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaInvoiceHistory;
use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaNumberingCheck;
use Infixs\SquidCore\Integrations\WooCommerce\ActivityRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin\InvoiceAdminDownload;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin\InvoiceAttentionNotice;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin\InvoiceMetaBox;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin\InvoiceOrderActions;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Admin\InvoiceOrderColumn;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeBackfill;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeSnapshotJobs;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeSnapshotRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeSnapshotService;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe\DanfeXmlParser;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Front\InvoiceCustomerArea;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\Front\InvoiceCustomerDownload;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceApi;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceDownloadResponder;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceEligibility;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceJobs;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceOrderWatcher;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoicePresenter;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceProductSync;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceScheduler;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceService;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceSettings;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\NfeNumberingApi;
use Infixs\SquidCore\Integrations\WooCommerce\FeatureCompatibility;
use Infixs\SquidCore\Integrations\WooCommerce\Products\CorreiosNcmCopy;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalDataSource;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportAutoOpen;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportJobs;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportJournal;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportNotice;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportNoticeBoard;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportOverview;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportPlanner;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportPusher;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportRunner;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportRunRepository;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportScanner;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportService;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportSuggestion;
use Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport\FiscalImportWatchdog;
use Infixs\SquidCore\Integrations\WooCommerce\Products\MissingNcmProductsView;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCoverage;
use Infixs\SquidCore\Integrations\WooCommerce\Products\NcmCoverageStatus;
use Infixs\SquidCore\Integrations\WooCommerce\Products\ProductNcmField;
use Infixs\SquidCore\Integrations\WooCommerce\Products\ProductNcmResolver;
use Infixs\SquidCore\Integrations\WooCommerce\Products\ProductNcmStore;
use Infixs\SquidCore\Integrations\WooCommerce\SyncDispatcher;
use Infixs\SquidCore\Integrations\WooCommerce\SyncStatusRepository;
use Infixs\SquidCore\Integrations\WooCommerce\WooCommerceApiKeys;
use Infixs\SquidCore\Integrations\WooCommerce\WooCommerceIntegration;
use Infixs\SquidCore\Services\ConnectionService;
use Infixs\SquidCore\Services\OAuthConnectService;
use Infixs\SquidCore\Services\SyncService;
use Infixs\SquidCore\Support\BackgroundQueue;
use Infixs\SquidCore\Support\Credentials\CredentialProvider;
use Infixs\SquidCore\Support\Credentials\StoredCredentials;
use Infixs\SquidCore\Support\NoticeDismissals;
use Infixs\SquidCore\Support\OAuth\OAuthPendingRequests;
use Infixs\SquidCore\Support\OAuth\OAuthRevocation;
use Infixs\SquidCore\Support\OAuth\OAuthServer;
use Infixs\SquidCore\Support\OAuth\OAuthTokenStore;
use Infixs\SquidCore\Support\OptionLock;
use Infixs\SquidCore\Support\SquidCoreClient;
use Infixs\SquidCore\Support\SquidCoreUrls;

class AppServiceProvider extends ServiceProvider {
	/**
	 * Register any application services.
	 */
	public function register(): void {
		$this->app->singleton( SquidCoreUrls::class, function ( $app ) {
			return new SquidCoreUrls(
				$app->config()->string( 'app.api_base_url', 'https://api.squidcore.com' ),
				$app->config()->string( 'app.app_url', 'https://app.squidcore.com' )
			);
		} );

		$this->app->singleton( OAuthTokenStore::class, function () {
			return new OAuthTokenStore();
		} );

		$this->app->singleton( StoredCredentials::class, function ( $app ) {
			return new StoredCredentials( $app->settings(), $app->make( OAuthTokenStore::class ), $app->make( SquidCoreUrls::class ) );
		} );

		$this->app->singleton( WooCommerceApiKeys::class, function () {
			return new WooCommerceApiKeys();
		} );

		$this->app->singleton( OAuthRevocation::class, function () {
			return new OAuthRevocation( $this->interactiveClientFactory() );
		} );

		$this->app->singleton( OAuthServer::class, function ( $app ) {
			return new OAuthServer( $app->make( SquidCoreUrls::class ), $app->config()->string( 'app.oauth_client_id', '' ) );
		} );

		$this->app->singleton( SquidCoreClient::class, function ( $app ) {
			/** @var SquidCoreUrls $urls */
			$urls = $app->make( SquidCoreUrls::class );

			// An override that would send the token unencrypted leaves the background sync unconfigured. The token is
			// read on every request, so long background batches follow a new connection or a disconnection.
			return new SquidCoreClient(
				$urls->isApiUrlUsable() ? $urls->apiUrl() : '',
				$app->make( StoredCredentials::class )
			);
		} );

		$this->app->singleton( SyncStatusRepository::class, function () {
			return new SyncStatusRepository();
		} );

		$this->app->singleton( ActivityRepository::class, function () {
			return new ActivityRepository();
		} );

		$this->app->singleton( BackgroundQueue::class, function () {
			return new BackgroundQueue();
		} );

		$this->app->singleton( OptionLock::class, function () {
			return new OptionLock();
		} );

		$this->app->singleton( CorreiosAutomatico::class, function () {
			return new CorreiosAutomatico();
		} );

		$this->app->singleton( WebmaniaBR::class, function () {
			return new WebmaniaBR();
		} );

		$this->registerProductNcm();

		$this->app->singleton( SyncDispatcher::class, function ( $app ) {
			return new SyncDispatcher(
				$app->make( SquidCoreClient::class ),
				$app->make( SyncStatusRepository::class ),
				$app->make( ActivityRepository::class ),
				$app->make( BackgroundQueue::class ),
				$app->make( ProductNcmResolver::class )
			);
		} );

		$this->app->singleton( WooCommerceIntegration::class, function ( $app ) {
			return new WooCommerceIntegration(
				$app->make( SyncDispatcher::class ),
				$app->settings(),
				$app->make( ConnectionService::class )
			);
		} );

		$this->app->singleton( ConnectionService::class, function ( $app ) {
			return new ConnectionService(
				$app->settings(),
				$app->make( SquidCoreUrls::class ),
				\infixs_squidcore_version(),
				$this->interactiveClientFactory(),
				$app->make( SyncStatusRepository::class ),
				$app->make( ActivityRepository::class ),
				$app->make( StoredCredentials::class ),
				$app->make( OAuthTokenStore::class ),
				$app->make( WooCommerceApiKeys::class ),
				$app->make( OAuthRevocation::class )
			);
		} );

		$this->app->singleton( OAuthConnectService::class, function ( $app ) {
			return new OAuthConnectService(
				$app->make( SquidCoreUrls::class ),
				$app->make( OAuthServer::class ),
				new OAuthPendingRequests(),
				$app->make( OAuthTokenStore::class ),
				$app->make( WooCommerceApiKeys::class ),
				$app->make( ConnectionService::class ),
				$app->make( OAuthRevocation::class ),
				$this->interactiveClientFactory(),
				$app->config()->string( 'app.oauth_scopes', '' ),
				\infixs_squidcore_version()
			);
		} );

		$this->app->singleton( OAuthCallback::class, function ( $app ) {
			return new OAuthCallback( $app->make( OAuthConnectService::class ) );
		} );

		$this->app->singleton( SyncService::class, function ( $app ) {
			return new SyncService(
				$app->settings(),
				$app->make( SyncDispatcher::class ),
				$app->make( SyncStatusRepository::class ),
				$app->make( ActivityRepository::class )
			);
		} );

		$this->registerInvoices();
		$this->registerFiscalImportRuns();
	}

	/**
	 * The single NCM field of the products and where the NCM is kept.
	 */
	private function registerProductNcm(): void {
		$this->app->singleton( ProductNcmStore::class, function ( $app ) {
			return new ProductNcmStore( $app->make( CorreiosAutomatico::class ) );
		} );

		$this->app->singleton( ProductNcmResolver::class, function ( $app ) {
			return new ProductNcmResolver( $app->make( ProductNcmStore::class ) );
		} );

		$this->app->singleton( ProductNcmField::class, function ( $app ) {
			return new ProductNcmField(
				$app->make( ProductNcmStore::class ),
				$app->make( CorreiosAutomatico::class ),
				$app->make( WebmaniaBR::class )
			);
		} );

		$this->app->singleton( CorreiosNcmCopy::class, function ( $app ) {
			return new CorreiosNcmCopy( $app->make( BackgroundQueue::class ), $app->make( ProductNcmStore::class ), $app->make( CorreiosAutomatico::class ) );
		} );

		$this->app->singleton( NcmCoverage::class, function ( $app ) {
			return new NcmCoverage( $app->make( BackgroundQueue::class ) );
		} );

		$this->app->singleton( MissingNcmProductsView::class, function ( $app ) {
			return new MissingNcmProductsView( $app->make( NcmCoverage::class ) );
		} );

		$this->registerFiscalImport();
	}

	/**
	 * Fiscal data of the WebmaniaBR plugin, read to import it, and its NF-e numbering.
	 */
	private function registerFiscalImport(): void {
		$this->app->singleton( WebmaniaFiscalSource::class, function ( $app ) {
			return new WebmaniaFiscalSource( $app->make( WebmaniaBR::class ) );
		} );

		$this->app->singleton( FiscalDataSource::class, function ( $app ) {
			return $app->make( WebmaniaFiscalSource::class );
		} );

		$this->app->singleton( FiscalImportPlanner::class, function () {
			return new FiscalImportPlanner();
		} );

		$this->app->singleton( NfeNumberingApi::class, function ( $app ) {
			return new NfeNumberingApi( $app->make( SquidCoreClient::class )->withMaxAttempts( 1 ) );
		} );

		$this->app->singleton( WebmaniaNumberingCheck::class, function ( $app ) {
			return new WebmaniaNumberingCheck(
				$app->make( WebmaniaInvoiceHistory::class ),
				$app->make( WebmaniaBR::class ),
				$app->make( NfeNumberingApi::class ),
				$app->make( ConnectionService::class ),
				$app->make( SquidCoreUrls::class )
			);
		} );
	}

	/**
	 * The import of the WebmaniaBR NCM: runs, scan, push to SquidCore, notices and the count of products without NCM
	 * the app shows. After the invoices, whose settings the notices read.
	 */
	private function registerFiscalImportRuns(): void {
		$this->app->singleton( NcmCoverageStatus::class, function ( $app ) {
			return new NcmCoverageStatus(
				$app->make( NcmCoverage::class ),
				$app->make( MissingNcmProductsView::class ),
				$app->make( ConnectionService::class ),
				$app->make( InvoiceSettings::class )
			);
		} );

		$this->app->singleton( FiscalImportRunRepository::class, function () {
			return new FiscalImportRunRepository();
		} );

		$this->app->singleton( FiscalImportJournal::class, function () {
			return new FiscalImportJournal();
		} );

		$this->app->singleton( FiscalImportPusher::class, function ( $app ) {
			return new FiscalImportPusher(
				$app->make( FiscalImportRunRepository::class ),
				$app->make( FiscalImportJournal::class ),
				$app->make( SyncDispatcher::class ),
				$app->make( BackgroundQueue::class ),
				$app->make( ConnectionService::class ),
				$app->make( SyncService::class )
			);
		} );

		$this->app->singleton( FiscalImportRunner::class, function ( $app ) {
			return new FiscalImportRunner(
				$app->make( FiscalDataSource::class ),
				$app->make( FiscalImportPlanner::class ),
				$app->make( ProductNcmStore::class ),
				$app->make( FiscalImportRunRepository::class ),
				$app->make( FiscalImportJournal::class ),
				$app->make( OptionLock::class ),
				$app->make( BackgroundQueue::class ),
				$app->make( NcmCoverage::class ),
				$app->make( FiscalImportPusher::class )
			);
		} );

		$this->app->singleton( FiscalImportScanner::class, function ( $app ) {
			return new FiscalImportScanner(
				$app->make( FiscalDataSource::class ),
				$app->make( FiscalImportPlanner::class ),
				$app->make( ProductNcmStore::class ),
				$app->make( FiscalImportRunRepository::class ),
				$app->make( OptionLock::class ),
				$app->make( BackgroundQueue::class ),
				$app->make( CorreiosAutomatico::class )
			);
		} );

		$this->app->singleton( FiscalImportWatchdog::class, function ( $app ) {
			return new FiscalImportWatchdog( $app->make( FiscalImportRunRepository::class ), $app->make( BackgroundQueue::class ) );
		} );

		$this->app->singleton( FiscalImportJobs::class, function ( $app ) {
			return new FiscalImportJobs(
				$app->make( FiscalImportRunner::class ),
				$app->make( FiscalImportScanner::class ),
				$app->make( FiscalImportPusher::class ),
				$app->make( FiscalImportWatchdog::class )
			);
		} );

		$this->app->singleton( FiscalImportSuggestion::class, function ( $app ) {
			return new FiscalImportSuggestion( $app->make( WebmaniaBR::class ), $app->make( FiscalImportRunRepository::class ) );
		} );

		$this->app->singleton( FiscalImportNoticeBoard::class, function ( $app ) {
			return new FiscalImportNoticeBoard(
				$app->make( FiscalImportSuggestion::class ),
				$app->make( FiscalImportRunRepository::class ),
				$app->make( WebmaniaBR::class ),
				$app->make( InvoiceSettings::class ),
				new NoticeDismissals()
			);
		} );

		$this->app->singleton( FiscalImportOverview::class, function ( $app ) {
			return new FiscalImportOverview(
				$app->make( WebmaniaBR::class ),
				$app->make( FiscalImportRunRepository::class ),
				$app->make( FiscalImportScanner::class ),
				$app->make( FiscalImportPusher::class ),
				$app->make( FiscalImportWatchdog::class ),
				$app->make( FiscalImportSuggestion::class ),
				$app->make( FiscalImportNoticeBoard::class ),
				$app->make( CorreiosAutomatico::class ),
				$app->make( NcmCoverageStatus::class )
			);
		} );

		$this->app->singleton( FiscalImportService::class, function ( $app ) {
			return new FiscalImportService(
				$app->make( FiscalImportRunRepository::class ),
				$app->make( FiscalImportRunner::class ),
				$app->make( FiscalImportScanner::class ),
				$app->make( FiscalImportPusher::class ),
				$app->make( FiscalImportJournal::class ),
				$app->make( FiscalImportOverview::class ),
				$app->make( FiscalDataSource::class ),
				$app->make( WebmaniaBR::class ),
				$app->make( CorreiosAutomatico::class ),
				$app->make( NcmCoverage::class )
			);
		} );

		$this->app->singleton( FiscalImportNotice::class, function ( $app ) {
			return new FiscalImportNotice( $app->make( FiscalImportNoticeBoard::class ) );
		} );

		$this->app->singleton( FiscalImportAutoOpen::class, function ( $app ) {
			return new FiscalImportAutoOpen( $app->make( FiscalImportSuggestion::class ) );
		} );
	}

	/**
	 * Clients for the calls a person waits for: one attempt, to fail fast.
	 *
	 * @return \Closure(string, CredentialProvider): SquidCoreClient
	 */
	private function interactiveClientFactory(): \Closure {
		return function ( string $api_url, CredentialProvider $credential ): SquidCoreClient {
			return new SquidCoreClient( $api_url, $credential, 1 );
		};
	}

	private function registerInvoices(): void {
		$this->app->singleton( InvoiceSettings::class, function ( $app ) {
			return new InvoiceSettings( $app->settings() );
		} );

		$this->app->singleton( InvoiceRepository::class, function ( $app ) {
			return new InvoiceRepository( $app->make( ActivityRepository::class ) );
		} );

		$this->app->singleton( InvoiceEligibility::class, function () {
			return new InvoiceEligibility();
		} );

		$this->app->singleton( InvoicePresenter::class, function ( $app ) {
			return new InvoicePresenter( $app->make( InvoiceRepository::class ) );
		} );

		$this->app->singleton( InvoiceApi::class, function ( $app ) {
			return new InvoiceApi( $app->make( SquidCoreClient::class ) );
		} );

		$this->app->singleton( InvoiceService::class, function ( $app ) {
			return new InvoiceService(
				$app->make( InvoiceApi::class ),
				$app->make( InvoiceRepository::class ),
				$app->make( InvoiceSettings::class ),
				$app->make( InvoiceEligibility::class ),
				$app->make( SyncDispatcher::class ),
				$app->make( ConnectionService::class ),
				$app->make( BackgroundQueue::class ),
				$app->make( InvoiceProductSync::class ),
				$app->make( ActivityRepository::class ),
				$app->make( OptionLock::class )
			);
		} );

		$this->app->singleton( InvoiceProductSync::class, function ( $app ) {
			return new InvoiceProductSync( $app->make( SyncDispatcher::class ), $app->make( ProductNcmResolver::class ) );
		} );

		$this->app->singleton( InvoiceJobs::class, function ( $app ) {
			return new InvoiceJobs(
				$app->make( InvoiceService::class ),
				$app->make( InvoiceSettings::class ),
				$app->make( InvoiceEligibility::class )
			);
		} );

		$this->app->singleton( InvoiceScheduler::class, function ( $app ) {
			return new InvoiceScheduler(
				$app->make( InvoiceService::class ),
				$app->make( InvoiceSettings::class ),
				$app->make( InvoiceEligibility::class )
			);
		} );

		$this->app->singleton( InvoiceOrderWatcher::class, function ( $app ) {
			return new InvoiceOrderWatcher( $app->make( InvoiceService::class ) );
		} );

		$this->registerDanfe();

		$this->app->singleton( CorreiosInvoiceBridge::class, function ( $app ) {
			return new CorreiosInvoiceBridge(
				$app->make( InvoiceService::class ),
				$app->make( InvoiceSettings::class ),
				$app->make( ConnectionService::class ),
				$app->make( DanfeSnapshotService::class )
			);
		} );

		$this->app->singleton( WebmaniaInvoiceHistory::class, function () {
			return new WebmaniaInvoiceHistory();
		} );

		$this->app->singleton( WebmaniaInvoiceBridge::class, function ( $app ) {
			return new WebmaniaInvoiceBridge( $app->make( WebmaniaInvoiceHistory::class ) );
		} );

		$this->registerInvoiceScreens();
	}

	/**
	 * The simplified DANFE snapshots read from the invoice XML, for Correios Automático to print.
	 */
	private function registerDanfe(): void {
		$this->app->singleton( DanfeXmlParser::class, function () {
			return new DanfeXmlParser();
		} );

		$this->app->singleton( DanfeSnapshotRepository::class, function () {
			return new DanfeSnapshotRepository();
		} );

		$this->app->singleton( DanfeSnapshotService::class, function ( $app ) {
			return new DanfeSnapshotService(
				$app->make( InvoiceApi::class ),
				$app->make( InvoiceService::class ),
				$app->make( DanfeXmlParser::class ),
				$app->make( DanfeSnapshotRepository::class ),
				$app->make( CorreiosAutomatico::class ),
				$app->make( ConnectionService::class ),
				$app->make( BackgroundQueue::class )
			);
		} );

		$this->app->singleton( DanfeBackfill::class, function ( $app ) {
			return new DanfeBackfill(
				$app->make( InvoiceRepository::class ),
				$app->make( InvoiceService::class ),
				$app->make( DanfeSnapshotService::class ),
				$app->make( BackgroundQueue::class )
			);
		} );

		$this->app->singleton( DanfeSnapshotJobs::class, function ( $app ) {
			return new DanfeSnapshotJobs( $app->make( DanfeSnapshotService::class ), $app->make( DanfeBackfill::class ) );
		} );
	}

	/**
	 * Order screen, downloads and customer area of the invoices.
	 */
	private function registerInvoiceScreens(): void {
		$this->app->singleton( InvoiceDownloadResponder::class, function ( $app ) {
			return new InvoiceDownloadResponder( $app->make( InvoiceApi::class ), $app->make( InvoiceRepository::class ), $app->make( ConnectionService::class ) );
		} );

		$this->app->singleton( InvoiceAdminDownload::class, function ( $app ) {
			return new InvoiceAdminDownload( $app->make( InvoiceDownloadResponder::class ) );
		} );

		$this->app->singleton( InvoiceCustomerDownload::class, function ( $app ) {
			return new InvoiceCustomerDownload( $app->make( InvoiceDownloadResponder::class ), $app->make( InvoiceService::class ) );
		} );

		$this->app->singleton( InvoiceCustomerArea::class, function ( $app ) {
			return new InvoiceCustomerArea( $app->make( InvoiceService::class ), $app->make( InvoiceSettings::class ), $app->make( 'view' ) );
		} );

		$this->app->singleton( InvoiceMetaBox::class, function ( $app ) {
			return new InvoiceMetaBox(
				$app->make( InvoiceService::class ),
				$app->make( InvoicePresenter::class ),
				$app->make( 'view' ),
				$app->make( SquidCoreUrls::class ),
				$app->make( DanfeSnapshotService::class ),
				$app->make( CorreiosAutomatico::class )
			);
		} );

		$this->app->singleton( InvoiceOrderColumn::class, function ( $app ) {
			return new InvoiceOrderColumn( $app->make( InvoiceRepository::class ), $app->make( DanfeSnapshotService::class ), $app->make( CorreiosAutomatico::class ) );
		} );

		$this->app->singleton( InvoiceOrderActions::class, function ( $app ) {
			return new InvoiceOrderActions( $app->make( InvoiceService::class ), $app->make( DanfeSnapshotService::class ), $app->make( CorreiosAutomatico::class ) );
		} );

		$this->app->singleton( InvoiceAttentionNotice::class, function ( $app ) {
			return new InvoiceAttentionNotice( $app->make( InvoiceRepository::class ) );
		} );
	}

	/**
	 * Bootstrap any application services.
	 */
	public function boot(): void {
		( new FeatureCompatibility( \INFIXS_SQUIDCORE_FILE_NAME ) )->register();

		$this->app->booted( function ( $app ) {
			$plugin_version = \infixs_squidcore_version();
			$current_version = get_option( 'infixs_squidcore_version', '0.0.0' );
			if ( version_compare( $plugin_version, $current_version, '>' ) ) {
				//load old version before update options
				$migrator = $this->app->make( 'migrator' );
				update_option( 'infixs_squidcore_version', $plugin_version );
				$migrator->run();
			}
		} );

		add_action( 'plugins_loaded', [ $this, 'afterPluginLoaded' ] );
	}

	public function afterPluginLoaded() {
		/**
		 * The push handler must be registered on every request (admin, front,
		 * cron), otherwise scheduled sync jobs would never run.
		 *
		 * @var SyncDispatcher $dispatcher
		 */
		$dispatcher = $this->app->make( SyncDispatcher::class );
		$dispatcher->registerHandler();

		/**
		 * Same for the invoice jobs.
		 *
		 * @var InvoiceJobs $invoice_jobs
		 */
		$invoice_jobs = $this->app->make( InvoiceJobs::class );
		$invoice_jobs->registerHandlers();

		/**
		 * And for the simplified DANFE snapshots.
		 *
		 * @var DanfeSnapshotJobs $danfe_jobs
		 */
		$danfe_jobs = $this->app->make( DanfeSnapshotJobs::class );
		$danfe_jobs->registerHandlers();

		/** @var WooCommerceIntegration $integration */
		$integration = $this->app->make( WooCommerceIntegration::class );
		$integration->init();

		// Registered without WooCommerce too: the return then tells why the store cannot connect.
		$this->app->make( OAuthCallback::class )->register();

		// Without WooCommerce too, so the activation mark is always consumed (the tab only opens with data to import).
		$this->app->make( FiscalImportAutoOpen::class )->register();

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$this->app->make( InvoiceRepository::class )->register();
		$this->app->make( InvoiceScheduler::class )->register();
		$this->app->make( InvoiceOrderWatcher::class )->register();
		$this->app->make( ProductNcmField::class )->register();
		$this->app->make( CorreiosNcmCopy::class )->register();
		$this->app->make( NcmCoverage::class )->register();

		$this->app->make( FiscalImportJobs::class )->register();
		$this->app->make( FiscalImportJournal::class )->register();

		if ( is_admin() ) {
			$this->app->make( MissingNcmProductsView::class )->register();
			$this->app->make( FiscalImportNotice::class )->register();
		}
		$this->app->make( DanfeSnapshotService::class )->register();
		$this->app->make( CorreiosInvoiceBridge::class )->register();
		$this->app->make( WebmaniaInvoiceBridge::class )->register();

		$this->app->make( InvoiceAdminDownload::class )->register();
		$this->app->make( InvoiceCustomerDownload::class )->register();
		$this->app->make( InvoiceCustomerArea::class )->register();
		$this->app->make( InvoiceMetaBox::class )->register();
		$this->app->make( InvoiceOrderColumn::class )->register();
		$this->app->make( InvoiceOrderActions::class )->register();
		$this->app->make( InvoiceAttentionNotice::class )->register();
	}
}
