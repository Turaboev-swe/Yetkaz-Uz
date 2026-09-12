<?php

namespace App\Services\Broadcast;

use App\Enums\BroadcastAudience;
use App\Jobs\SendBroadcastMessage;
use App\Models\Broadcast;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * /admin dan xabarnoma yuborish: yozuv yaratadi, auditoriyani aniqlaydi va
 * har foydalanuvchi uchun alohida job navbatga qo'yadi.
 *
 * Job'lar bir-biridan STAGGER_MS millisekund oralatib qo'yiladi — Telegram'ning
 * umumiy yuborish limiti (~30 xabar/soniya) buzilmasligi uchun (bu yerda ~20/soniya).
 */
class BroadcastService
{
    private const STAGGER_MS = 50;

    /**
     * @param  array<int, int|string>  $districtIds
     * @return array{broadcast: Broadcast, target_count: int}
     */
    public function send(
        string $message,
        ?string $imagePath,
        BroadcastAudience $audience,
        array $districtIds,
        ?Staff $creator,
    ): array {
        $districtIds = array_values(array_map('intval', $districtIds));

        $broadcast = Broadcast::create([
            'message' => $message,
            'image_path' => $imagePath,
            'audience_type' => $audience,
            'district_ids' => $audience === BroadcastAudience::District ? $districtIds : null,
            'sent_count' => 0,
            'failed_count' => 0,
            'created_by' => $creator?->id,
        ]);

        $userIds = $this->resolveAudience($audience, $districtIds);

        foreach ($userIds->values() as $i => $userId) {
            SendBroadcastMessage::dispatch($broadcast->id, $userId)
                ->delay(now()->addMilliseconds($i * self::STAGGER_MS));
        }

        return ['broadcast' => $broadcast, 'target_count' => $userIds->count()];
    }

    /**
     * @param  array<int, int>  $districtIds
     * @return Collection<int, int> foydalanuvchi id'lari
     */
    private function resolveAudience(BroadcastAudience $audience, array $districtIds): Collection
    {
        if ($audience === BroadcastAudience::All) {
            return User::query()
                ->whereNotNull('telegram_id')
                ->where('profile_completed', true)
                ->pluck('id');
        }

        return User::query()
            ->whereHas('addresses', fn ($q) => $q->whereIn('district_id', $districtIds))
            ->distinct()
            ->pluck('id');
    }
}
