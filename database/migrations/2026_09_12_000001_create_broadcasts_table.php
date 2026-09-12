<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * /admin panelidan yuboriladigan xabarnomalar (broadcast). Har biri
 * SendBroadcastMessage job'lari orqali navbatga qo'yiladi — bir foydalanuvchi
 * uchun bitta job. sent_count/failed_count job'lar bajarilgan sari (atomik
 * increment bilan) o'sib boradi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->text('message');
            $table->string('image_path')->nullable(); // storage/app/public/broadcasts/...
            $table->string('audience_type', 16)->default('all'); // all | district
            $table->json('district_ids')->nullable(); // audience_type=district bo'lganda
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE broadcasts ADD CONSTRAINT broadcasts_audience_type_check CHECK (audience_type IN ('all','district'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};
