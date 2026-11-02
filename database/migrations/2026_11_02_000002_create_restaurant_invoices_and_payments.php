<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Oylik hisob-kitoblar (restaurant_invoices) va to'lovlar (restaurant_payments). Pullar tiyinda.
 *
 * Tarif (plan, rate_bp, fixed_fee, billing_started_at) invoice ichida SNAPSHOT — restoran tarifi
 * keyin o'zgarsa ham eski hisoblar o'zgarmaydi (qayta hisoblash ham snapshot bilan). `orders_total` — komissiya bazasi
 * (taom summasi − restoran ulushidagi chegirma, faqat yetkazilganlar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->date('period'); // oyning 1-kuni (Asia/Tashkent)
            $table->string('plan', 16); // snapshot
            $table->unsignedInteger('rate_bp'); // snapshot
            $table->unsignedBigInteger('fixed_fee'); // snapshot, tiyin
            $table->date('billing_started_at')->nullable(); // snapshot: proratsiya va buyurtmalar oynasi
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedBigInteger('orders_total')->default(0);
            $table->unsignedBigInteger('amount_due')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->string('status', 16)->default('unpaid');
            $table->date('due_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'period']);
            $table->index('period');
            $table->index('status');
        });

        DB::statement("ALTER TABLE restaurant_invoices ADD CONSTRAINT restaurant_invoices_plan_check CHECK (plan IN ('percent','fixed'))");
        DB::statement("ALTER TABLE restaurant_invoices ADD CONSTRAINT restaurant_invoices_status_check CHECK (status IN ('unpaid','partial','paid','waived'))");

        Schema::create('restaurant_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('restaurant_invoices')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount'); // tiyin
            $table->date('paid_at');
            $table->string('method', 16);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('paid_at');
        });

        DB::statement('ALTER TABLE restaurant_payments ADD CONSTRAINT restaurant_payments_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE restaurant_payments ADD CONSTRAINT restaurant_payments_method_check CHECK (method IN ('cash','card','transfer'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_payments');
        Schema::dropIfExists('restaurant_invoices');
    }
};
