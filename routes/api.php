<?php

use Illuminate\Support\Facades\Route;
use Tetrix\AiBridge\Http\Controllers\BridgeController;

/*
|--------------------------------------------------------------------------
| AI Bridge API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the AiBridgeServiceProvider. They provide
| HTTP endpoints for token generation and bridge status checking.
|
| All routes require authentication (the consuming app's auth middleware).
|
*/

Route::prefix('ai-bridge')->group(function () {
    Route::post('/token', [BridgeController::class, 'generateToken']);
    Route::get('/status', [BridgeController::class, 'status']);
});
