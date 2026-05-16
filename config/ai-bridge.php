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

        // Audience claim that scopes tokens to this application instance, so
        // tokens issued for one deployment are rejected by another that shares
        // the same secret. Defaults to config('app.url') when left null.
        'audience' => env('AI_BRIDGE_TOKEN_AUDIENCE'),
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
        'stream_timeout' => env('AI_BRIDGE_STREAM_TIMEOUT', 300), // seconds before stream HTTP timeout

        // Optional allowlist of permitted model values for client-supplied 'model' overrides.
        // When non-empty, requests with a model not in this list are rejected with HTTP 422.
        // Leave empty to allow any model (not recommended in managed or shared deployments).
        // Example: ['gpt-4o', 'gpt-4o-mini']
        'allowed_models' => [],
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
        'host' => env('AI_BRIDGE_SERVER_HOST', '127.0.0.1'),
        'port' => env('AI_BRIDGE_SERVER_PORT', 8085),
        'relay_timeout' => env('AI_BRIDGE_RELAY_TIMEOUT', 5), // seconds for internal HTTP relay

        // URL for internal relay requests (PHP-FPM → bridge server communication).
        // Override to use HTTPS if the bridge server is behind a TLS-terminating proxy.
        // Security: When running the bridge server on a separate host, use HTTPS to
        // protect internal relay tokens in transit.
        'relay_url' => env('AI_BRIDGE_RELAY_URL'),

        // The public-facing WebSocket URL returned in the token response.
        // Set this when the bridge server is behind a TLS-terminating reverse proxy
        // (e.g. nginx) so clients receive a wss:// URL instead of the internal ws:// address.
        // If null (default), the token endpoint returns ws://{server.host}:{server.port}.
        // Example: AI_BRIDGE_PUBLIC_URL=wss://bridge.example.com
        'public_url' => env('AI_BRIDGE_PUBLIC_URL'),
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

    /*
    |--------------------------------------------------------------------------
    | Streaming Options
    |--------------------------------------------------------------------------
    */

    'streaming' => [
        // When true, thinking/reasoning block events are suppressed from SSE and
        // broadcast outputs. Defaults to TRUE to prevent AI chain-of-thought reasoning
        // from being accidentally exposed to end users — set to false only when you
        // intentionally want to display AI reasoning in your UI (e.g. a debug view).
        'suppress_thinking_blocks' => env('AI_BRIDGE_SUPPRESS_THINKING', true),

        // Maximum length of the user-supplied system_prompt field in non-managed mode.
        // Prevents excessively large prompts from consuming disproportionate API tokens.
        'max_system_prompt_length' => env('AI_BRIDGE_MAX_SYSTEM_PROMPT_LENGTH', 10000),
    ],
];
