<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workspaces a bridge last advertised.
 *
 * Cached beside `last_providers` and for the same reason: the live registry
 * lives in the WebSocket server's memory, which a PHP-FPM worker cannot see.
 * The app needs this to render a workspace picker on a page load that happens
 * to catch the bridge between reconnects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_bridge_connections', function (Blueprint $table) {
            $table->json('last_workspaces')->nullable()->after('last_providers');
        });
    }

    public function down(): void
    {
        Schema::table('ai_bridge_connections', function (Blueprint $table) {
            $table->dropColumn('last_workspaces');
        });
    }
};
