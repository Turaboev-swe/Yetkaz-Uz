<?php

namespace App\Filament\Admin\Resources;

use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Filament\Admin\Resources\FeedbackResource\Pages;
use App\Models\Feedback;
use App\Services\Feedback\FeedbackReplyService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Bot menyusidagi "💬 Taklif va shikoyat" orqali kelgan xabarlar — FAQAT
 * KO'RISH (UserResource bilan bir xil naqsh). Faqat platform_admin (FeedbackPolicy).
 */
class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    // "feedback" inglizchada ko'plik shakli yo'q so'z — Filament avtomat
    // /admin/feedback (birlik) yasardi, aniqlik uchun ko'plik qo'lda beriladi.
    protected static ?string $slug = 'feedbacks';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Fikr-mulohazalar';

    protected static ?string $modelLabel = 'Fikr-mulohaza';

    protected static ?string $pluralModelLabel = 'Fikr-mulohazalar';

    protected static ?int $navigationSort = 7;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Sana')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Turi')
                    ->badge()
                    ->formatStateUsing(fn (FeedbackType $state): string => $state->icon().' '.$state->label())
                    ->color(fn (FeedbackType $state): string => $state === FeedbackType::Complaint ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('user.full_name')
                    ->label('Foydalanuvchi')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('message')
                    ->label('Matn')
                    ->limit(60)
                    ->wrap(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Holat')
                    ->badge()
                    ->formatStateUsing(fn (FeedbackStatus $state): string => $state->label())
                    ->color(fn (FeedbackStatus $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Holat')
                    ->options(collect(FeedbackStatus::cases())->mapWithKeys(fn (FeedbackStatus $s) => [$s->value => $s->label()])->all()),

                SelectFilter::make('type')
                    ->label('Turi')
                    ->options([
                        FeedbackType::Suggestion->value => FeedbackType::Suggestion->icon().' '.FeedbackType::Suggestion->label(),
                        FeedbackType::Complaint->value => FeedbackType::Complaint->icon().' '.FeedbackType::Complaint->label(),
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ko\'rish'),
                static::replyAction(),
            ])
            ->bulkActions([])
            ->emptyStateHeading("Hali fikr-mulohaza yo'q");
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('created_at')->label('Sana')->dateTime('d.m.Y H:i'),
            Infolists\Components\TextEntry::make('type')->label('Turi')
                ->formatStateUsing(fn (FeedbackType $state): string => $state->icon().' '.$state->label()),
            Infolists\Components\TextEntry::make('user.full_name')->label('Foydalanuvchi')->placeholder('—'),
            Infolists\Components\TextEntry::make('user.phone')->label('Telefon')->placeholder('—'),
            Infolists\Components\TextEntry::make('message')->label('Matn')->columnSpanFull(),

            Infolists\Components\Section::make('Admin javobi')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Infolists\Components\TextEntry::make('status')->label('Holat')
                        ->formatStateUsing(fn (FeedbackStatus $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('reply_delivered')->label('Yetkazilishi')
                        ->state(fn (Feedback $record): string => match ($record->reply_delivered) {
                            true => '✅ Yetkazildi',
                            false => '❌ Yetkazilmadi (bot bloklangan)',
                            null => $record->admin_reply === null ? '—' : '⏳ Yuborilmoqda',
                        })
                        ->visible(fn (Feedback $record): bool => $record->admin_reply !== null),
                    Infolists\Components\TextEntry::make('admin_reply')->label('Javob')->columnSpanFull()
                        ->placeholder('Hali javob berilmagan'),
                    Infolists\Components\TextEntry::make('repliedBy.name')->label('Kim javob berdi')
                        ->visible(fn (Feedback $record): bool => $record->admin_reply !== null),
                    Infolists\Components\TextEntry::make('replied_at')->label('Qachon')->dateTime('d.m.Y H:i')
                        ->visible(fn (Feedback $record): bool => $record->admin_reply !== null),
                ]),
        ]);
    }

    /** "Javob berish" (yangi) yoki "Qayta javob" (eskisi o'rniga saqlanadi). */
    public static function replyAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reply')
            ->label(fn (Feedback $record): string => $record->status === FeedbackStatus::Answered ? 'Qayta javob' : 'Javob berish')
            ->icon('heroicon-o-paper-airplane')
            ->authorize('reply')
            ->modalHeading(fn (Feedback $record): string => $record->status === FeedbackStatus::Answered ? 'Qayta javob (eskisi o‘rniga saqlanadi)' : 'Mijozga javob')
            ->modalDescription(fn (Feedback $record): string => $record->message)
            ->form(static::replyFormSchema())
            ->action(function (Feedback $record, array $data): void {
                static::sendReply($record, $data);
            });
    }

    /** @return array<int, Forms\Components\Component> */
    public static function replyFormSchema(): array
    {
        return [
            Forms\Components\Textarea::make('reply')
                ->label('Javob matni')
                ->required()
                ->rule('regex:/\S/u')
                ->maxLength(3000)
                ->rows(6),
        ];
    }

    /** @param array{reply: string} $data */
    public static function sendReply(Feedback $record, array $data): void
    {
        app(FeedbackReplyService::class)->reply($record, Filament::auth()->user(), $data['reply']);

        Notification::make()->title('Javob mijozga yuborilmoqda')->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeedbacks::route('/'),
            'view' => Pages\ViewFeedback::route('/{record}'),
        ];
    }
}
