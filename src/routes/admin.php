<?php

use Infixs\SquidCore\AvelPress\Facades\Route;
use Infixs\SquidCore\Http\Controllers\Admin\SettingsController;

defined( 'ABSPATH' ) || exit;

Route::page( 'infixs-squidcore', [ 'hidden_notices' => true ] )->group( function () {
	Route::get( '*', [ SettingsController::class, 'index' ] );
} );
