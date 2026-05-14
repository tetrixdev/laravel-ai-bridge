<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active Mode
    |--------------------------------------------------------------------------
    |
    | Determines how AI responses are generated.
    |
    | - 'bridge': Responses come from a CLI bridge connected via WebSocket.
    | - 'byok':   Responses come from the Chat Completions API using a
    |             user-provided API key (Bring Your Own Key).
    | - 'managed': Responses come from the Chat Completions API using an
    |              application-managed API key.
    |
    */

    'mode' => env('AI_BRIDGE_MODE', 'byok'),

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to all AI Bridge HTTP routes. Defaults to ['auth']
    | to ensure routes are protected out of the box. Customize this to use
    | your app's auth guard (e.g. ['auth:sanctum'], ['auth:api']).
    |
    | Set to an empty array to disable default auth middleware (not recommended).
    |
    */

    'route_middleware' => ['auth'],

    /*
    |--------------------------------------------------------------------------
    | JWT Token Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for JWT tokens used to authenticate bridge connections.
    |
    */

    'token' => [
        'secret' => env('AI_BRIDGE_TOKEN_SECRET'),
        'ttl' => env('AI_BRIDGE_TOKEN_TTL', 86400), // 24 hours in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | WebSocket Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the WebSocket bridge connection.
    |
    */

    'websocket' => [
        'heartbeat_interval' => 30,   // seconds between ping/pong
        'request_timeout' => 300,     // seconds before an AI request times out
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat Completions Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the Chat Completions API (used in BYOK and managed modes).
    |
    */

    'chat_completions' => [
        'endpoint' => env('AI_BRIDGE_ENDPOINT'),       // e.g. https://api.openai.com
        'api_key' => env('AI_BRIDGE_API_KEY'),          // User's key (BYOK) or app's key (managed)
        'model' => env('AI_BRIDGE_MODEL'),              // e.g. gpt-4o
        'max_tokens' => env('AI_BRIDGE_MAX_TOKENS', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bridge WebSocket Server Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the dedicated WebSocket server that handles CLI bridge
    | connections. This is separate from Laravel Reverb — it runs on its own
    | port and speaks the AI Bridge Protocol.
    |
    */

    'server' => [
        'host' => env('AI_BRIDGE_SERVER_HOST', '0.0.0.0'),
        'port' => env('AI_BRIDGE_SERVER_PORT', 8085),
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for broadcasting AI stream events via Laravel Reverb.
    | When enabled, you can use AiBridge::streamAndBroadcast() to push
    | events to a Reverb channel for real-time browser updates.
    |
    */

    'broadcasting' => [
        'enabled' => env('AI_BRIDGE_BROADCAST', true),
        'connection' => env('AI_BRIDGE_BROADCAST_CONNECTION', 'reverb'),
    ],
];
