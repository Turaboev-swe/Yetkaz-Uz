<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use Throwable;

/**
 * /admin dan yuborilgan xabarnomaning BITTA foydalanuvchiga yetkazilishi.
 *
 * Bitta job — bitta foydalanuvchi. Xato (bloklangan, deaktivatsiya bo'lgan
 * hisob va h.k.) BOSHQA job'larga ta'sir qilmaydi — bu yerda ushlanadi va
 * `failed_count` ga yoziladi, qayta urinilmaydi (best-effort, bir martalik
 * yuborish).
 *
 * Navbatga qo'yishda BroadcastService har job'ni bir necha o'n millisekund
 * oralatib (`->delay()`) qo'yadi — Telegram'ning umumiy yuborish limitini
 * (~30 xabar/soniya) buzmaslik uchun.
 */
class SendBroadcastMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Bir martalik urinish — muvaffaqiyatsizlik shu yerda hisoblanadi, qayta urinish yo'q. */
    public int $tries = 1;

    public function __construct(
        public readonly int $broadcastId,
        public readonly int $userId,
    ) {}

    public function handle(Nutgram $bot): void
    {
        $broadcast = Broadcast::find($this->broadcastId);
        $user = User::find($this->userId);

        if ($broadcast === null || $user === null || blank($user->telegram_id)) {
            return;
        }

        try {
            if (filled($broadcast->image_path)) {
                $bot->sendPhoto(
                    photo: InputFile::make(Storage::disk('public')->path($broadcast->image_path)),
                    chat_id: $user->telegram_id,
                    caption: $broadcast->message,
                );
            } else {
                $bot->sendMessage(
                    text: $broadcast->message,
                    chat_id: $user->telegram_id,
                );
            }

            Broadcast::whereKey($broadcast->id)->increment('sent_count');
        } catch (TelegramException $e) {
            // Kutilgan holat — bloklangan, deaktivatsiya bo'lgan va h.k.
            Log::info('[broadcast] yuborilmadi', [
                'broadcast_id' => $broadcast->id,
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);
            Broadcast::whereKey($broadcast->id)->increment('failed_count');
        } catch (Throwable $e) {
            // Kutilmagan xato (masalan rasm fayli topilmadi) — baribir navbatni to'xtatmaymiz.
            Log::error('[broadcast] kutilmagan xato', [
                'broadcast_id' => $broadcast->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            Broadcast::whereKey($broadcast->id)->increment('failed_count');
        }
    }
}
