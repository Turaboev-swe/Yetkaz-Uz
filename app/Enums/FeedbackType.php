<?php

namespace App\Enums;

enum FeedbackType: string
{
    case Suggestion = 'suggestion';
    case Complaint = 'complaint';

    public function label(): string
    {
        return __("messages.feedback_type.{$this->value}");
    }

    public function icon(): string
    {
        return match ($this) {
            self::Suggestion => '💡',
            self::Complaint => '⚠️',
        };
    }

    /** Turi bo'yicha farqlangan mijozga javob (Claude.md talabi). */
    public function thanksMessage(): string
    {
        return match ($this) {
            self::Suggestion => __('messages.feedback.saved_suggestion'),
            self::Complaint => __('messages.feedback.saved_complaint'),
        };
    }
}
