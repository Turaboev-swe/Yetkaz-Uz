<?php

namespace App\Services\Feedback;

use App\Enums\FeedbackType;
use App\Jobs\NotifyPlatformAdminsOfFeedback;
use App\Models\Feedback;
use App\Models\User;

class FeedbackService
{
    public function submit(User $user, FeedbackType $type, string $message): Feedback
    {
        $feedback = Feedback::create([
            'user_id' => $user->id,
            'type' => $type,
            'message' => mb_substr(trim($message), 0, 2000),
        ]);

        NotifyPlatformAdminsOfFeedback::dispatch($feedback->id);

        return $feedback;
    }
}
