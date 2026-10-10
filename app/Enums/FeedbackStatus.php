<?php

namespace App\Enums;

enum FeedbackStatus: string
{
    case New = 'new';
    case Answered = 'answered';

    public function label(): string
    {
        return match ($this) {
            self::New => '🆕 Yangi',
            self::Answered => '✅ Javob berildi',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Answered => 'success',
        };
    }
}
