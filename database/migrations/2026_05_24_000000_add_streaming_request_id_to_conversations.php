<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `streaming_request_id` points at the currently-streaming turn for a
     * conversation, or null when no turn is in flight. The chat UI uses it on
     * page load to decide whether to attach an EventSource to the per-turn
     * event buffer for replay/resumption.
     *
     * Set at the start of `startConversationStream`, cleared by the recorder
     * on every terminal (done/error/cancelled). Bounded to one in-flight
     * turn per conversation by application convention — concurrent turns on
     * a single conversation are not a use case the package supports.
     */
    public function up(): void
    {
        Schema::table('ai_bridge_conversations', function (Blueprint $table) {
            $table->string('streaming_request_id', 64)->nullable()->after('cli_session_id');
            $table->index('streaming_request_id', 'ai_bridge_conversations_streaming_request_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_bridge_conversations', function (Blueprint $table) {
            $table->dropIndex('ai_bridge_conversations_streaming_request_id_index');
            $table->dropColumn('streaming_request_id');
        });
    }
};
