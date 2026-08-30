<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Panel xodimlari — Telegram foydalanuvchilaridan (`users`) alohida.
 * `staff` guard shu jadval bilan ishlaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            // platform_admin uchun null; restaurant_owner / kitchen_staff uchun majburiy.
            $table->foreignId('restaurant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            // platform_admin | restaurant_owner | kitchen_staff
            $table->string('role', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->unique('email');
            $table->index('restaurant_id');
            $table->index(['role', 'is_active']);
        });

        DB::statement("ALTER TABLE staff ADD CONSTRAINT staff_role_check CHECK (role IN ('platform_admin','restaurant_owner','kitchen_staff'))");

        // platform_admin da restaurant_id null, boshqalarda majburiy.
        DB::statement(<<<'SQL'
            ALTER TABLE staff ADD CONSTRAINT staff_restaurant_required
            CHECK (
                (role = 'platform_admin' AND restaurant_id IS NULL)
                OR (role <> 'platform_admin' AND restaurant_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
