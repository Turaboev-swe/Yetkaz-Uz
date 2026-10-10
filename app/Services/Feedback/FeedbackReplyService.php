<?php

namespace App\Services\Feedback;

use App\Enums\FeedbackStatus;
use App\Jobs\SendFeedbackReplyToCustomer;
use App\Models\Feedback;
use App\Models\Staff;
use Illuminate\Validation\ValidationException;

class FeedbackReplyService
{
    /**
     * Javobni saqlaydi (qayta javobda eskisi o'rniga yoziladi) va mijozga
     * yuborishni navbatga qo'yadi. Faqat platform_admin.
     */
    public function reply(Feedback $feedback, Staff $staff, string $reply): Feedback
    {
        abort_unless($staff->isPlatformAdmin(), 403);

        $reply = trim($reply);

        if ($reply === '') {
            throw ValidationException::withMessages(['reply' => 'Javob matni bo‘sh bo‘lishi mumkin emas.']);
        }

        $feedback->update([
            'status' => FeedbackStatus::Answered,
            'admin_reply' => mb_substr($reply, 0, 3000),
            'replied_at' => now(),
            'replied_by' => $staff->id,
            'reply_delivered' => null,
        ]);

        SendFeedbackReplyToCustomer::dispatch($feedback->id);

        return $feedback;
    }
}
