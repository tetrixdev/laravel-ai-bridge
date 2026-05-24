<?php

use Illuminate\Support\Facades\Route;
use Tetrix\AiBridge\Http\Controllers\AssetController;
use Tetrix\AiBridge\Http\Controllers\BridgeController;
use Tetrix\AiBridge\Http\Controllers\ConnectionController;
use Tetrix\AiBridge\Http\Controllers\ConversationController;
use Tetrix\AiBridge\Http\Controllers\StreamController;
use Tetrix\AiBridge\Http\Controllers\StreamEventsController;

// Static assets for the chat Web Component — public (no auth): the component JS.
// Kept outside the auth-gated group below.
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

    // Conversation-less SSE streaming endpoint — throttle:10,1.
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/stream/sse', [StreamController::class, 'sse']);
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

    // Per-turn stream-event endpoints.
    //
    // /events is a PHP-FPM long-poll that may hold an FPM worker for up to
    // ai-bridge.stream_store.max_connection_s; client EventSource reconnects
    // pick up via Last-Event-ID for free. Throttling is comfortably above any
    // realistic UI churn.
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/streams/{requestId}/status', [StreamEventsController::class, 'status'])
            ->where('requestId', '[A-Za-z0-9_-]+');
        Route::get('/streams/{requestId}/events', [StreamEventsController::class, 'events'])
            ->where('requestId', '[A-Za-z0-9_-]+');
        Route::post('/streams/{requestId}/abort', [StreamEventsController::class, 'abort'])
            ->where('requestId', '[A-Za-z0-9_-]+');
    });
});
