<?php

namespace App\Filament\Admin\Resources\FeedbackResource\Pages;

use App\Enums\FeedbackStatus;
use App\Filament\Admin\Resources\FeedbackResource;
use App\Models\Feedback;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewFeedback extends ViewRecord
{
    protected static string $resource = FeedbackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reply')
                ->label(fn (Feedback $record): string => $record->status === FeedbackStatus::Answered ? 'Qayta javob' : 'Javob berish')
                ->icon('heroicon-o-paper-airplane')
                ->authorize('reply')
                ->modalDescription(fn (Feedback $record): string => $record->message)
                ->form(FeedbackResource::replyFormSchema())
                ->action(function (Feedback $record, array $data): void {
                    FeedbackResource::sendReply($record, $data);

                    $this->record->refresh();
                    $this->refreshFormData(['status', 'admin_reply', 'replied_at', 'replied_by', 'reply_delivered']);
                }),
        ];
    }
}
