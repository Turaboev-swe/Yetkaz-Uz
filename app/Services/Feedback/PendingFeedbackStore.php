<?php

namespace App\Services\Feedback;

use App\Enums\FeedbackType;
use Illuminate\Support\Facades\Cache;

/**
 * "Kutilayotgan fikr-mulohaza" holati — foydalanuvchi turini tanladi
 * (taklif/shikoyat), lekin matnni hali yozmagan.
 *
 * PendingRatingStore bilan bir xil naqsh (Cache/Redis, foydalanuvchi bo'yicha,
 * bitta faol holat). 1 soatdan keyin eskiradi — undan keyin oddiy matn
 * fikr-mulohaza sifatida qabul qilinmaydi.
 */
class PendingFeedbackStore
{
    private const TTL = 60 * 60;

    public function remember(int $telegramUserId, FeedbackType $type): void
    {
        Cache::put($this->key($telegramUserId), $type->value, self::TTL);
    }

    public function pending(int $telegramUserId): ?FeedbackType
    {
        $value = Cache::get($this->key($telegramUserId));

        return $value !== null ? FeedbackType::tryFrom($value) : null;
    }

    public function forget(int $telegramUserId): void
    {
        Cache::forget($this->key($telegramUserId));
    }

    private function key(int $telegramUserId): string
    {
        return "feedback:pending:{$telegramUserId}";
    }
}
