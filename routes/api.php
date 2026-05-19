<?php

use Illuminate\Support\Facades\Route;
use Tetrix\AiBridge\Http\Controllers\AssetController;
use Tetrix\AiBridge\Http\Controllers\BridgeController;
use Tetrix\AiBridge\Http\Controllers\BroadcastAuthController;
use Tetrix\AiBridge\Http\Controllers\ConnectionController;
use Tetrix\AiBridge\Http\Controllers\ConversationController;
use Tetrix\AiBridge\Http\Controllers\StreamController;

// Static assets for the chat Web Component — public (no auth): the component
// JS and the vendored pusher/echo. Kept outside the auth-gated group below.
Route::middleware('api')->prefix('ai-bridge')->group(function () {
    Route::get('/assets/{file}', [AssetController::class, 'show'])
        ->where('file', '[A-Za-z0-9._-]+');
});

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

    // Conversation + connection management — throttle:120,1 (120 requests per
    // minute). Generous enough for a browsing UI that fetches a conversation
    // and its messages on each click. Access is scoped through the project-
    // supplied resolvers (AiBridge::resolveConversationsUsing / …Connections).
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations/{id}', [ConversationController::class, 'show']);
        Route::delete('/conversations/{id}', [ConversationController::class, 'destroy']);

        Route::get('/connections', [ConnectionController::class, 'index']);
        Route::post('/connections', [ConnectionController::class, 'store']);
        Route::patch('/connections/{id}', [ConnectionController::class, 'update']);
        Route::post('/connections/{id}/regenerate', [ConnectionController::class, 'regenerate']);
        Route::delete('/connections/{id}', [ConnectionController::class, 'destroy']);
    });

    // Conversation streaming — throttle:30,1 (30 messages per minute). Each call
    // sends one chat message; the AI response itself takes seconds, so this is
    // a comfortable ceiling for an active conversation.
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/conversations/{id}/stream', [ConversationController::class, 'stream']);
    });

    // Channel authorization for the package's private Reverb channels. Used in
    // place of Laravel's stock /broadcasting/auth, which requires an
    // authenticated user; this endpoint authorizes via the project resolvers
    // instead, so the chat component works with or without Laravel auth.
    // throttle:60,1 — one call per channel subscription (connections + the
    // active conversation), comfortably above any realistic UI churn.
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);
    });
});
