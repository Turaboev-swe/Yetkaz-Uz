<?php

namespace App\Models;

use App\Enums\FeedbackStatus;
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
        'status',
        'admin_reply',
        'replied_at',
        'replied_by',
        'reply_delivered',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => FeedbackType::class,
            'status' => FeedbackStatus::class,
            'replied_at' => 'datetime',
            'reply_delivered' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Staff, Feedback> */
    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'replied_by');
    }

    /** @return BelongsTo<User, Feedback> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
