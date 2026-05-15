<?php

use Illuminate\Support\Facades\Route;
use Tetrix\AiBridge\Http\Controllers\BridgeController;
use Tetrix\AiBridge\Http\Controllers\StreamController;

/*
|--------------------------------------------------------------------------
| AI Bridge API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the AiBridgeServiceProvider within the 'api'
| middleware group. Authentication middleware is applied by default via
| the 'ai-bridge.route_middleware' config key (defaults to ['auth']).
|
| To customize the auth middleware (e.g. for Sanctum):
|   'route_middleware' => ['auth:sanctum'],
|
| To disable the default auth middleware entirely:
|   'route_middleware' => [],
|
*/

$middleware = array_filter(array_merge(
    ['api'],
    (array) config('ai-bridge.route_middleware', ['auth']),
));

Route::middleware($middleware)->prefix('ai-bridge')->group(function () {
    // Token and status endpoints — throttle:20,1 (20 requests per minute)
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/token', [BridgeController::class, 'generateToken']);
        Route::get('/status', [BridgeController::class, 'status']);
    });

    // Streaming endpoints — throttle:10,1 (10 requests per minute)
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/stream/sse', [StreamController::class, 'sse']);
        Route::post('/stream/broadcast', [StreamController::class, 'broadcast']);
    });
});
