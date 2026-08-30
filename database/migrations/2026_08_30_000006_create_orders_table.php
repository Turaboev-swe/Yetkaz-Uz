<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 16);

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();

            // Taomlar snapshot bilan: [{"product_id":1,"name":"...","price":25000,"qty":2,"note":null}]
            $table->jsonb('items');
            // Yetkazish manzili snapshot (manzil keyin o'chsa ham buyurtma buzilmaydi).
            $table->jsonb('address_snapshot')->nullable();

            $table->unsignedBigInteger('subtotal');      // tiyin
            $table->unsignedBigInteger('delivery_fee');  // tiyin
            $table->unsignedBigInteger('total');         // tiyin

            // payme | click | cash
            $table->string('payment_method', 16)->default('cash');
            // pending | paid | failed | refunded
            $table->string('payment_status', 16)->default('pending');
            // new | accepted | preparing | on_the_way | delivered | cancelled
            $table->string('status', 16)->default('new');

            $table->unsignedSmallInteger('eta_minutes')->nullable();
            $table->decimal('distance_km', 6, 2)->nullable();

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique('order_number');
            $table->index('user_id');
            $table->index('restaurant_id');
            $table->index('status');
            $table->index('payment_status');
            // Oshxona paneli: restorandagi faol buyurtmalar (navbat jarimasi hisobi).
            $table->index(['restaurant_id', 'status']);
            // Mijoz buyurtmalari tarixi.
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('new','accepted','preparing','on_the_way','delivered','cancelled'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_method_check CHECK (payment_method IN ('payme','click','cash'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('pending','paid','failed','refunded'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
