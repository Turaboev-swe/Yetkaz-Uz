<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taklif/shikoyatga admin javobi. Mavjud yozuvlar 'new' holatida qoladi.
 * reply_delivered: null = hali yuborilmagan (navbatda), true/false = natija.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedbacks', function (Blueprint $table) {
            $table->string('status', 16)->default('new')->after('message');
            $table->text('admin_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->boolean('reply_delivered')->nullable();

            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE feedbacks ADD CONSTRAINT feedbacks_status_check CHECK (status IN ('new','answered'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE feedbacks DROP CONSTRAINT IF EXISTS feedbacks_status_check');

        Schema::table('feedbacks', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('replied_by');
            $table->dropColumn(['status', 'admin_reply', 'replied_at', 'reply_delivered']);
        });
    }
};
