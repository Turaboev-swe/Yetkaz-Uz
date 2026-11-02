<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Print agent heartbeat: agent har 30 s da `POST /api/agent/heartbeat` yuboradi, shu ustunga yoziladi.
 * Jim bo'lib qolsa (config print.agent_offline_after_seconds) oshxona paneli va adminda ogohlantirish chiqadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->timestamp('print_agent_last_seen_at')->nullable()->after('print_agent_token');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', fn (Blueprint $table) => $table->dropColumn('print_agent_last_seen_at'));
    }
};
