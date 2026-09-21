<?php

namespace App\Jobs;

use App\Enums\StaffRole;
use App\Models\Feedback;
use App\Models\Staff;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

/**
 * Yangi taklif/shikoyat kelganda barcha platform_admin'larga (telegram_chat_id
 * to'ldirilgan, faol) darhol DM. NotifyKitchenStaffOfNewOrder bilan bir xil
 * naqsh — bitta admin botni bloklagan bo'lsa qolganlarga baribir yuboriladi.
 */
class NotifyPlatformAdminsOfFeedback implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 20;

    public function __construct(public readonly int $feedbackId) {}

    public function handle(Nutgram $bot): void
    {
        $feedback = Feedback::with('user')->find($this->feedbackId);

        if ($feedback === null) {
            return;
        }

        $recipients = Staff::query()
            ->where('role', StaffRole::PlatformAdmin->value)
            ->where('is_active', true)
            ->whereNotNull('telegram_chat_id')
            ->pluck('telegram_chat_id');

        if ($recipients->isEmpty()) {
            return;
        }

        $text = __('messages.feedback.admin_notify', [
            'type_label' => $feedback->type->label(),
            'name' => $feedback->user->full_name ?: '—',
            'phone' => $feedback->user->phone ?: '—',
            'message' => $feedback->message,
        ]);

        foreach ($recipients as $chatId) {
            try {
                $bot->sendMessage(text: $text, chat_id: (int) $chatId);
            } catch (TelegramException $e) {
                Log::info('[feedback] admin\'ga yuborilmadi', [
                    'feedback_id' => $feedback->id,
                    'chat_id' => $chatId,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}
