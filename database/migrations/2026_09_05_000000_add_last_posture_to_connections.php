<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CLI isolation posture a bridge last reported.
 *
 * Cached beside `last_providers` and `last_workspaces`, and for the same
 * reason: the live registry lives in the WebSocket server's memory, which a
 * PHP-FPM worker cannot see. The app needs this to show that a connection is
 * running as something other than what the server asked for — otherwise the
 * only trace is a log line on somebody else's machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_bridge_connections', function (Blueprint $table) {
            $table->json('last_posture')->nullable()->after('last_workspaces');
        });
    }

    public function down(): void
    {
        Schema::table('ai_bridge_connections', function (Blueprint $table) {
            $table->dropColumn('last_posture');
        });
    }
};
