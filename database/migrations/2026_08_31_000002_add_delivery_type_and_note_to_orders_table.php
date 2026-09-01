<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buyurtma turi (yetkazish / olib ketish) va mijoz izohi.
 *
 * - pickup: yetkazish narxi olinmaydi, ETA faqat pishirish vaqti, radius filtri yo'q
 * - note: "qo'ng'iroqsiz, eshik oldiga qo'ying" kabi
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_type', 16)->default('delivery')->after('address_id');
            $table->string('note', 500)->nullable()->after('address_snapshot');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_delivery_type_check CHECK (delivery_type IN ('delivery','pickup'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_delivery_type_check');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_type', 'note']);
        });
    }
};
