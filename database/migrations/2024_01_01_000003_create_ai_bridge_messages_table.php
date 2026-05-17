<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the ai_bridge_messages table.
 *
 * One row per turn. `content` is the flat text of the turn; `blocks` is the
 * ordered list of typed blocks (text / thinking / tool_call / tool_result)
 * used for faithful UI replay. `historyFor()` rebuilds chat history from these
 * rows — including tool calls/results, excluding thinking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_bridge_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->constrained('ai_bridge_conversations')->cascadeOnDelete();
            $table->string('role'); // user | assistant | tool
            $table->longText('content');
            $table->json('blocks')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('usage')->nullable();
            $table->boolean('incomplete')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_bridge_messages');
    }
};
