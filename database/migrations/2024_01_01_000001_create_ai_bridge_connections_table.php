<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the ai_bridge_connections table.
 *
 * A "connection" is a source an AI conversation streams from:
 *  - type=bridge: a CLI bridge connection. `connection_key` is the identifier
 *    the bridge WebSocket server routes by (the JWT subject the bridge uses).
 *  - type=byok:   a Chat Completions endpoint. `endpoint` + `api_key` (encrypted
 *    at the model layer) hold the credentials.
 * `managed` mode needs no row — it uses application config.
 *
 * The table is intentionally NOT linked to any project table. Consuming apps
 * link connections to their own owner/session models via their own pivots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_bridge_connections', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // bridge | byok
            $table->string('name')->nullable();
            $table->string('connection_key')->nullable()->index();
            $table->string('endpoint')->nullable();
            $table->text('api_key')->nullable(); // encrypted via the Connection model cast
            $table->json('last_providers')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_bridge_connections');
    }
};
