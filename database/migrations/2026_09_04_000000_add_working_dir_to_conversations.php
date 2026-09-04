<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The directory a bridge conversation works in.
 *
 * Persisted per conversation rather than passed per turn, because the bridge
 * fixes a working directory for the life of a CLI session and refuses a resume
 * that names a different one. Without somewhere to remember it, the second
 * turn of every conversation would have to be told again by whatever sent the
 * first — and getting that wrong fails as `working_dir_changed`, mid-chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_bridge_conversations', function (Blueprint $table) {
            // Nullable, and null is the norm: a conversation with no working
            // directory is an ordinary chat, which is what every existing row
            // is and what every new one is until somebody picks a workspace.
            $table->string('working_dir', 1024)->nullable()->after('cli_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_bridge_conversations', function (Blueprint $table) {
            $table->dropColumn('working_dir');
        });
    }
};
