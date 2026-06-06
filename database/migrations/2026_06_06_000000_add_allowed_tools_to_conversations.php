<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `allowed_tools` to ai_bridge_conversations.
 *
 * A conversation may restrict which registered tools it exposes to the model.
 * `null` (the default) means "all registered tools" — fully back-compatible;
 * an array of tool names means "only these". This lets one app run several
 * conversations off the same global ToolRegistry, each with its own tool set
 * (e.g. distinct agents), without a per-conversation registry.
 *
 * Security note: `allowed_tools` is meant to be assigned server-side (e.g. when
 * the app creates the conversation), NOT supplied by the client — the package's
 * own conversation-create endpoint does not accept it. A client must not be able
 * to widen its own tool access; the runtime guard in MessageHandler enforces the
 * stored list regardless of what a bridge client requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_bridge_conversations', function (Blueprint $table) {
            $table->json('allowed_tools')->nullable()->after('tools_hash');
        });
    }

    public function down(): void
    {
        Schema::table('ai_bridge_conversations', function (Blueprint $table) {
            $table->dropColumn('allowed_tools');
        });
    }
};
