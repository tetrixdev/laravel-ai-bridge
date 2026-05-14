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
];
