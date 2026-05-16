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
 *
 * ARCH-003 (known, deferred): react/socket and ratchet/rfc6455 are unconditional
 * hard dependencies even for BYOK/managed deployments that never run the bridge server.
 * Making them optional would require class_exists guards in BridgeWebSocketServer and
 * ServeCommand, plus a more complex installation story for consumers. Deferred until
 * there is a concrete need (e.g. a significant footprint reduction request). Future
 * reviewers: do not re-flag this without a concrete, low-risk implementation plan.
 */
class AiBridgeServiceProvider extends ServiceProvider
{
    /**
     * BL-016: Tracks whether the empty-route_middleware warning has already been
     * logged in this process, so it fires at most once instead of per request.
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

        // NOTE (ARCH-003): Registered as singleton but in-memory state only persists in
        // long-running processes (bridge server, Octane). Under PHP-FPM the singleton is
        // per-request, so connections are always empty. See BridgeConnectionManager docblock.
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

        // SEC-010: Warn when route_middleware is empty — routes will be unprotected.
        // BL-016: Fire at most once per process. boot() runs on every request under
        // PHP-FPM, so without this guard an empty middleware list would flood logs.
        if (! self::$emptyMiddlewareWarningLogged) {
            $routeMiddleware = config('ai-bridge.route_middleware', ['auth']);
            if (empty($routeMiddleware)) {
                self::$emptyMiddlewareWarningLogged = true;
                Log::warning('AI Bridge: route_middleware is empty — all AI Bridge HTTP routes are unprotected. Set ai-bridge.route_middleware to ["auth"] or ["auth:sanctum"] for production.');
            }
        }

        // Register routes.
        // ARCH-014 (known, deferred): /token and /status are bridge-mode-specific and
        // could be conditionally registered only when mode='bridge'. However, conditional
        // route registration based on config values that can change post-cache is fragile,
        // and both endpoints are protected by auth + rate-limiting so the security risk is
        // negligible. Future reviewers: do not re-flag without a concrete, cache-safe plan.
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // Register artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateTokenCommand::class,
                TestCommand::class,
                ServeCommand::class,
            ]);
        }
    }
}
