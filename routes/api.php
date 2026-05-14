<?php

use Illuminate\Support\Facades\Route;
use Tetrix\AiBridge\Http\Controllers\BridgeController;

/*
|--------------------------------------------------------------------------
| AI Bridge API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the AiBridgeServiceProvider within the 'api'
| middleware group. They provide HTTP endpoints for token generation and
| bridge status checking.
|
| IMPORTANT: The consuming application should add its own auth middleware
| to protect these routes. This package does not force a specific auth
| middleware because different apps use different authentication systems
| (Sanctum, Passport, custom, etc.). You can do this by adding middleware
| in your app's RouteServiceProvider or by publishing and customizing
| these routes.
|
| Example in your app:
|   Route::middleware(['auth:sanctum'])->group(function () {
|       // AI Bridge routes will be available at /api/ai-bridge/*
|   });
|
*/

Route::middleware(['api'])->prefix('ai-bridge')->group(function () {
    Route::post('/token', [BridgeController::class, 'generateToken']);
    Route::get('/status', [BridgeController::class, 'status']);
});
