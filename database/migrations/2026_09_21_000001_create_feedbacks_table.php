<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bot menyusidagi "💬 Taklif va shikoyat" orqali kelgan xabarlar.
 * Faqat yaratiladi, hech qachon tahrirlanmaydi — shuning uchun faqat created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 16); // suggestion | complaint
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['type', 'created_at']);
        });

        DB::statement("ALTER TABLE feedbacks ADD CONSTRAINT feedbacks_type_check CHECK (type IN ('suggestion','complaint'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
    }
};
