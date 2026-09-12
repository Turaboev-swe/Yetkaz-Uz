<?php

namespace App\Models;

use App\Enums\BroadcastAudience;
use Database\Factories\BroadcastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * /admin panelidan yuborilgan xabarnoma. Faqat ko'rish/yuborish uchun —
 * qayta tahrirlash yoki qayta yuborish yo'q (Broadcasts sahifasi, tarix jadvali).
 */
class Broadcast extends Model
{
    /** @use HasFactory<BroadcastFactory> */
    use HasFactory;

    protected $fillable = [
        'message',
        'image_path',
        'audience_type',
        'district_ids',
        'sent_count',
        'failed_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'audience_type' => BroadcastAudience::class,
            'district_ids' => 'array',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Staff, Broadcast> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    /** Tuman nomlari, vergul bilan (faqat audience_type=district uchun mazmunli). */
    public function districtNames(): string
    {
        if ($this->audience_type !== BroadcastAudience::District || blank($this->district_ids)) {
            return '';
        }

        return District::query()
            ->whereIn('id', $this->district_ids)
            ->orderBy('name')
            ->pluck('name')
            ->implode(', ');
    }
}
