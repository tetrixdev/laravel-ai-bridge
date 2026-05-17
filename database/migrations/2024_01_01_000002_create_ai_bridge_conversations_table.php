<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the ai_bridge_conversations table.
 *
 * A conversation is the unit of multi-turn chat. It is deliberately unlinked
 * to any project table — consuming apps associate conversations with their own
 * owner/session models via their own pivot tables.
 *
 * `provider` / `model` are the *current* selection; `session_provider` /
 * `session_model` record what the live CLI session (`cli_session_id`) was
 * established with, so the server can decide resume-vs-reset on each turn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_bridge_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('mode'); // bridge | byok | managed
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('system_prompt')->nullable();
            $table->foreignId('connection_id')->nullable()
                ->constrained('ai_bridge_connections')->nullOnDelete();
            $table->string('cli_session_id')->nullable();
            $table->string('session_provider')->nullable();
            $table->string('session_model')->nullable();
            $table->string('tools_hash')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_bridge_conversations');
    }
};
