<?php

declare(strict_types=1);

namespace Tetrix\AiBridge;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Console\GenerateTokenCommand;
use Tetrix\AiBridge\Console\ServeCommand;
use Tetrix\AiBridge\Console\TestCommand;
use Tetrix\AiBridge\Http\Middleware\ValidateBridgeToken;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/**
 * Laravel service provider for the AI Bridge package.
 *
 * Registers config, routes, and binds all core services as singletons.
 */
class AiBridgeServiceProvider extends ServiceProvider
{
    /**
     * Tracks whether the empty-route_middleware warning has already been logged
     * in this process, so it fires at most once instead of per request.
     */
    private static bool $emptyMiddlewareWarningLogged = false;

    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge package config with app config
        $this->mergeConfigFrom(
            __DIR__.'/../config/ai-bridge.php',
            'ai-bridge'
        );

        // Bind core services as singletons
        $this->app->singleton(ToolRegistry::class, function () {
            return new ToolRegistry();
        });

        $this->app->singleton(TokenManager::class, function () {
            return new TokenManager();
        });

        // Registered as a singleton, but in-memory state only persists in
        // long-running processes — see the BridgeConnectionManager docblock.
        $this->app->singleton(BridgeConnectionManager::class, function () {
            return new BridgeConnectionManager();
        });

        $this->app->singleton(MessageHandler::class, function ($app) {
            return new MessageHandler(
                $app->make(BridgeConnectionManager::class),
                $app->make(TokenManager::class),
                $app->make(ToolRegistry::class),
            );
        });

        $this->app->singleton(AiBridgeManager::class, function ($app) {
            return new AiBridgeManager(
                $app->make(ToolRegistry::class),
                $app->make(BridgeConnectionManager::class),
                $app->make(TokenManager::class),
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/ai-bridge.php' => config_path('ai-bridge.php'),
        ], 'ai-bridge-config');

        // Publish JavaScript client
        $this->publishes([
            __DIR__.'/../resources/js/ai-bridge.js' => resource_path('js/vendor/ai-bridge.js'),
        ], 'ai-bridge-js');

        // Register named middleware for bridge token validation
        $this->app['router']->aliasMiddleware('ai-bridge.token', ValidateBridgeToken::class);

        // Warn when route_middleware is empty — routes will be unprotected.
        // Fire at most once per process to avoid flooding logs under PHP-FPM.
        if (! self::$emptyMiddlewareWarningLogged) {
            $routeMiddleware = config('ai-bridge.route_middleware', ['auth']);
            if (empty($routeMiddleware)) {
                self::$emptyMiddlewareWarningLogged = true;
                Log::warning('AI Bridge: route_middleware is empty — all AI Bridge HTTP routes are unprotected. Set ai-bridge.route_middleware to ["auth"] or ["auth:sanctum"] for production.');
            }
        }

        // Register routes.
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // Conversation persistence migrations — merged (loaded), never published,
        // so consuming apps cannot fork them and break future package updates.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register the per-conversation broadcast channel. Authorization reuses
        // the project's conversations resolver, so a client may only listen on
        // a conversation it is allowed to see — no separate channels.php needed.
        $this->registerBroadcastChannel();

        // Register artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateTokenCommand::class,
                TestCommand::class,
                ServeCommand::class,
            ]);
        }
    }

    /**
     * Register the per-conversation private broadcast channel.
     *
     * Authorization delegates to the project-supplied conversations resolver:
     * a client may subscribe only to a conversation it is allowed to see.
     * Skipped silently when broadcasting is not configured in the host app.
     */
    private function registerBroadcastChannel(): void
    {
        if (! class_exists(\Illuminate\Support\Facades\Broadcast::class)) {
            return;
        }

        $prefix = (string) config('ai-bridge.persistence.channel_prefix', 'ai-bridge');

        try {
            \Illuminate\Support\Facades\Broadcast::channel(
                $prefix.'.conversation.{conversationId}',
                function ($user, $conversationId) {
                    return app(AiBridgeManager::class)
                        ->conversationsQuery(request())
                        ->whereKey($conversationId)
                        ->exists();
                },
            );
        } catch (\Throwable $e) {
            Log::warning('AI Bridge: could not register broadcast channel', ['error' => $e->getMessage()]);
        }
    }
}
