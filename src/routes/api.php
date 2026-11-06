<?php

use Infixs\SquidCore\AvelPress\Facades\Route;
use Infixs\SquidCore\Http\Controllers\Api\V1\ActivityV1Controller;
use Infixs\SquidCore\Http\Controllers\Api\V1\ConnectionV1Controller;
use Infixs\SquidCore\Http\Controllers\Api\V1\FiscalImportV1Controller;
use Infixs\SquidCore\Http\Controllers\Api\V1\InvoicesV1Controller;
use Infixs\SquidCore\Http\Controllers\Api\V1\NcmAiV1Controller;
use Infixs\SquidCore\Http\Controllers\Api\V1\NcmCoverageV1Controller;
use Infixs\SquidCore\Http\Controllers\Api\V1\NoticesV1Controller;
use Infixs\SquidCore\Http\Controllers\Api\V1\OAuthV1Controller;
use Infixs\SquidCore\Http\Controllers\Api\V1\OrderInvoiceV1Controller;
use Infixs\SquidCore\Http\Controllers\Api\V1\PlanLimitV1Controller;
use Infixs\SquidCore\Http\Controllers\Api\V1\SettingsV1Controller;
use Infixs\SquidCore\Http\Controllers\Api\V1\StatusV1Controller;

defined( 'ABSPATH' ) || exit;

Route::prefix( 'infixs-squidcore/v1' )->guards( [ 'manage_options' ] )->group( function () {
	Route::get( '/status', [ StatusV1Controller::class, 'show' ] );
	Route::get( '/activity', [ ActivityV1Controller::class, 'index' ] );

	Route::get( '/settings', [ SettingsV1Controller::class, 'show' ] );
	Route::post( '/settings/connection', [ SettingsV1Controller::class, 'updateConnection' ] );
	Route::post( '/settings/sync', [ SettingsV1Controller::class, 'updateSync' ] );
	Route::post( '/settings/invoices', [ SettingsV1Controller::class, 'updateInvoices' ] );

	Route::get( '/invoices', [ InvoicesV1Controller::class, 'index' ] );
	Route::get( '/invoices/pending', [ InvoicesV1Controller::class, 'pending' ] );
	Route::post( '/invoices/plan-limit/retry', [ PlanLimitV1Controller::class, 'retry' ] );
	Route::post( '/invoices/usage/refresh', [ PlanLimitV1Controller::class, 'refreshUsage' ] );

	Route::post( '/connection/test', [ ConnectionV1Controller::class, 'test' ] );
	Route::post( '/connection/verify', [ ConnectionV1Controller::class, 'verify' ] );
	Route::post( '/connection/disconnect', [ ConnectionV1Controller::class, 'disconnect' ] );
	Route::post( '/connection/oauth/start', [ OAuthV1Controller::class, 'start' ] );

	Route::get( '/fiscal-import', [ FiscalImportV1Controller::class, 'show' ] );
	Route::post( '/fiscal-import/scan', [ FiscalImportV1Controller::class, 'scan' ] );
	Route::post( '/fiscal-import/preview', [ FiscalImportV1Controller::class, 'preview' ] );
	Route::post( '/fiscal-import/start', [ FiscalImportV1Controller::class, 'start' ] );
	Route::post( '/fiscal-import/step', [ FiscalImportV1Controller::class, 'step' ] );
	Route::post( '/fiscal-import/stop', [ FiscalImportV1Controller::class, 'stop' ] );
	Route::post( '/fiscal-import/resume', [ FiscalImportV1Controller::class, 'resume' ] );
	Route::post( '/fiscal-import/push', [ FiscalImportV1Controller::class, 'push' ] );
	Route::post( '/fiscal-import/undo', [ FiscalImportV1Controller::class, 'undo' ] );
	Route::get( '/fiscal-import/runs/{id}/items', [ FiscalImportV1Controller::class, 'items' ] );
	Route::get( '/fiscal-import/numbering', [ FiscalImportV1Controller::class, 'numbering' ] );

	Route::post( '/notices/{id}/dismiss', [ NoticesV1Controller::class, 'dismiss' ] );
	Route::post( '/ncm-coverage/refresh', [ NcmCoverageV1Controller::class, 'refresh' ] );

	Route::post( '/ncm-ai-fill/preview', [ NcmAiV1Controller::class, 'preview' ] );
	Route::post( '/ncm-ai-fill/start', [ NcmAiV1Controller::class, 'start' ] );
	Route::post( '/ncm-ai-fill/stop', [ NcmAiV1Controller::class, 'stop' ] );
	Route::post( '/ncm-ai-fill/resume', [ NcmAiV1Controller::class, 'resume' ] );
	Route::get( '/ncm-ai-fill/runs/{id}/items', [ NcmAiV1Controller::class, 'items' ] );
} );

/*
 * Order screen actions, open to shop managers. A group of its own at the top level: a top-level group gets a new
 * router, so these guards never mix with the ones above.
 */
Route::prefix( 'infixs-squidcore/v1' )->guards( [ 'edit_shop_orders' ] )->group( function () {
	Route::get( '/orders/{id}/invoice', [ OrderInvoiceV1Controller::class, 'show' ] );
	Route::post( '/orders/{id}/invoice/emit', [ OrderInvoiceV1Controller::class, 'emit' ] );
	Route::post( '/orders/{id}/invoice/refresh', [ OrderInvoiceV1Controller::class, 'refresh' ] );
	Route::post( '/orders/{id}/invoice/cancel', [ OrderInvoiceV1Controller::class, 'cancel' ] );
	Route::post( '/orders/{id}/invoice/fill-ncm', [ OrderInvoiceV1Controller::class, 'fillNcm' ] );
} );

/*
 * The NCM suggestion of the product editor, open to who edits products (the route also checks that product). The bulk
 * filling, its confirmation included, stays with the settings above.
 */
Route::prefix( 'infixs-squidcore/v1' )->guards( [ 'edit_products' ] )->group( function () {
	Route::post( '/products/{id}/ncm-suggestion', [ NcmAiV1Controller::class, 'suggest' ] );
} );
