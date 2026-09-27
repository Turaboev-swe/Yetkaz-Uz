<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qabul qilinmagan buyurtma eslatmasining oxirgi bajarilgan bosqichi
 * (0 = hali yo'q). EscalateUnacceptedOrder bosqichni atomik "egallaydi"
 * (UPDATE ... WHERE escalation_stage < N) — job qayta ishlasa ham har bosqich
 * faqat bir marta yuboriladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('escalation_stage')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('escalation_stage');
        });
    }
};
