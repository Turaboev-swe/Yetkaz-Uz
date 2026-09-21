<?php

namespace App\Models;

use App\Enums\FeedbackType;
use Database\Factories\FeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    /** @use HasFactory<FeedbackFactory> */
    use HasFactory;

    // "feedback" — inglizchada ko'plik shakli yo'q so'z, Eloquent avtomatik
    // "feedbacks" deb topolmaydi, shuning uchun jadval nomi aniq ko'rsatiladi.
    protected $table = 'feedbacks';

    /** Faqat created_at bor — hech qachon tahrirlanmaydi. */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'type',
        'message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => FeedbackType::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, Feedback> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
