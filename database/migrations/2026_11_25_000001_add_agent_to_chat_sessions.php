<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Which agent (App\Enums\Agent) holds this conversation's memory (agent_thread_id).
        // Fixed when the session is created; existing sessions all talked to AURA.
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->string('agent', 16)->default('aura')->after('external_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_sessions', fn (Blueprint $table) => $table->dropColumn('agent'));
    }
};
