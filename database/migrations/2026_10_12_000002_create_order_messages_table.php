<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buyurtma doirasidagi mijoz <-> restoran chati. Faqat yangi jadval, mavjud
 * ma'lumotga tegmaydi.
 *
 * sender_id: customer uchun users.id, restaurant uchun staff.id — polimorf, shuning
 * uchun FK yo'q. U hech qachon tashqariga (panel/bot) chiqmaydi (maxfiylik).
 * read_at: restoran tomonidan o'qilgan vaqt (faqat customer xabarlari uchun).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('sender_type', 16); // customer | restaurant
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->string('body', 500);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        DB::statement("ALTER TABLE order_messages ADD CONSTRAINT order_messages_sender_type_check CHECK (sender_type IN ('customer','restaurant'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_messages');
    }
};
