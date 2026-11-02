<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * To'lovni bekor qilish — yozuv o'chirilmaydi, bekor qilingan deb belgilanadi (iz qoladi).
 * Hisobning amount_paid / status'i faqat bekor qilinmagan to'lovlardan hisoblanadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_payments', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('created_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('staff')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancelled_by');

            $table->index(['invoice_id', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_payments', function (Blueprint $table) {
            $table->dropIndex(['invoice_id', 'cancelled_at']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
