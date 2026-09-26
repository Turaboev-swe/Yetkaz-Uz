<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Models\User;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Throwable;

/**
 * /admin dan yuborilgan xabarnomaning BITTA foydalanuvchiga yetkazilishi.
 *
 * Bitta job — bitta foydalanuvchi. Xato (bloklangan, deaktivatsiya bo'lgan
 * hisob va h.k.) BOSHQA job'larga ta'sir qilmaydi — bu yerda ushlanadi va
 * `failed_count` ga yoziladi. Job qayta urinilmaydi; faqat tarmoq xatosi
 * (cURL timeout) bo'lsa so'rov job ichida BITTA marta takrorlanadi.
 *
 * Rasmli xabarnomada rasm bir marta yuklanadi, qolganlarga Telegram file_id
 * yuboriladi — sendPhoto() izohiga qarang.
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

    /** Rasm yuklash lock'i: egasi ko'pi bilan 2 urinish × 5s HTTP timeout + 1s kutadi. */
    private const UPLOAD_LOCK_SECONDS = 30;

    private const UPLOAD_LOCK_WAIT_SECONDS = 20;

    private const NETWORK_RETRY_DELAY_SECONDS = 1;

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
                $this->sendPhoto($bot, $broadcast, $user);
            } else {
                $this->withNetworkRetry(fn () => $bot->sendMessage(
                    text: $broadcast->message,
                    chat_id: $user->telegram_id,
                ));
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
            // Kutilmagan xato (masalan rasm fayli topilmadi, qayta urinishdan
            // keyin ham tarmoq xatosi) — baribir navbatni to'xtatmaymiz.
            Log::error('[broadcast] kutilmagan xato', [
                'broadcast_id' => $broadcast->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            Broadcast::whereKey($broadcast->id)->increment('failed_count');
        }
    }

    /**
     * Rasm faqat BIR MARTA yuklanadi: birinchi muvaffaqiyatli sendPhoto
     * javobidagi file_id `telegram_file_id` ga yoziladi, qolganlarga shu
     * string yuboriladi (qayta yuklash yo'q).
     *
     * Parallel job'lar: file_id hali yo'q bo'lsa, yuklash Redis lock ostida —
     * bir vaqtda faqat bitta job yuklaydi, qolganlari kutadi va lock
     * bo'shagach saqlangan file_id bilan yuboradi. Birinchi foydalanuvchi
     * botni bloklagan bo'lsa (file_id olinmadi), keyingi job lock'ni olib
     * yana yuklaydi. Yozish `whereNull` bilan atomik — birinchi muvaffaqiyatli
     * file_id qoladi, keyingisi uni ustiga yozmaydi.
     */
    private function sendPhoto(Nutgram $bot, Broadcast $broadcast, User $user): void
    {
        if (filled($broadcast->telegram_file_id)) {
            $this->sendPhotoByFileId($bot, $broadcast, $user, $broadcast->telegram_file_id);

            return;
        }

        $lock = Cache::lock("broadcast:{$broadcast->id}:photo-upload", self::UPLOAD_LOCK_SECONDS);

        try {
            $lock->block(self::UPLOAD_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            // Lock egasi juda uzoq yuklayapti — foydalanuvchini tashlab
            // ketmaymiz, o'zimiz yuklaymiz (file_id yozuvi baribir atomik).
            $lock = null;
        }

        try {
            $fileId = Broadcast::whereKey($broadcast->id)->value('telegram_file_id');

            if (filled($fileId)) {
                $this->sendPhotoByFileId($bot, $broadcast, $user, $fileId);

                return;
            }

            $message = $this->withNetworkRetry(fn () => $bot->sendPhoto(
                // Har urinishda yangi InputFile — oldingi stream o'qib bo'lingan bo'lishi mumkin.
                photo: InputFile::make(Storage::disk('public')->path($broadcast->image_path)),
                chat_id: $user->telegram_id,
                caption: $broadcast->message,
            ));

            // Eng katta o'lcham — ro'yxat oxirida. Arr::last: bo'sh ro'yxatda null (last() false qaytaradi).
            $uploadedFileId = $message instanceof Message ? Arr::last($message->photo ?? [])?->file_id : null;

            if (filled($uploadedFileId)) {
                Broadcast::whereKey($broadcast->id)
                    ->whereNull('telegram_file_id')
                    ->update(['telegram_file_id' => $uploadedFileId]);
            }
        } finally {
            $lock?->release();
        }
    }

    private function sendPhotoByFileId(Nutgram $bot, Broadcast $broadcast, User $user, string $fileId): void
    {
        $this->withNetworkRetry(fn () => $bot->sendPhoto(
            photo: $fileId,
            chat_id: $user->telegram_id,
            caption: $broadcast->message,
        ));
    }

    /**
     * Tarmoq xatosi (cURL timeout, ulanish uzilishi — Guzzle TransferException)
     * bo'lsa BITTA marta qayta urinadi. TelegramException (bloklangan, chat
     * topilmadi) bu yerga tushmaydi — undan qayta urinishning foydasi yo'q.
     *
     * @template T
     *
     * @param  callable(): T  $send
     * @return T
     */
    private function withNetworkRetry(callable $send): mixed
    {
        try {
            return $send();
        } catch (TransferException $e) {
            Log::warning('[broadcast] tarmoq xatosi, qayta urinilmoqda', [
                'broadcast_id' => $this->broadcastId,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            Sleep::for(self::NETWORK_RETRY_DELAY_SECONDS)->seconds();

            return $send();
        }
    }
}
