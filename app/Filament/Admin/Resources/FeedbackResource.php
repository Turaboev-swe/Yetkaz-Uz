<?php

namespace App\Filament\Admin\Resources;

use App\Enums\FeedbackType;
use App\Filament\Admin\Resources\FeedbackResource\Pages;
use App\Models\Feedback;
use Filament\Infolists;
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
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Turi')
                    ->options([
                        FeedbackType::Suggestion->value => FeedbackType::Suggestion->icon().' '.FeedbackType::Suggestion->label(),
                        FeedbackType::Complaint->value => FeedbackType::Complaint->icon().' '.FeedbackType::Complaint->label(),
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ko\'rish'),
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
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeedbacks::route('/'),
            'view' => Pages\ViewFeedback::route('/{record}'),
        ];
    }
}
