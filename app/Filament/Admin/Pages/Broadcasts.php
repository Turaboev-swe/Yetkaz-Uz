<?php

namespace App\Filament\Admin\Pages;

use App\Enums\BroadcastAudience;
use App\Models\Broadcast;
use App\Models\District;
use App\Models\Staff;
use App\Services\Broadcast\BroadcastService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * /admin — mijozlarga (Telegram foydalanuvchilariga) xabarnoma yuborish.
 * Faqat platform_admin. Yuborish ASINXRON (navbat) — shuning uchun bu sahifa
 * darhol "N ta foydalanuvchiga navbatga qo'yildi" deydi, haqiqiy
 * yuborilgan/xato sonini pastdagi tarix jadvali JONLI (poll) ko'rsatadi.
 */
class Broadcasts extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Xabarnoma';

    protected static ?string $title = 'Xabarnoma yuborish';

    protected static ?string $slug = 'broadcasts';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.admin.pages.broadcasts';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $staff = auth('admin')->user();

        return $staff instanceof Staff && $staff->isPlatformAdmin();
    }

    public function mount(): void
    {
        $this->form->fill(['audience_type' => BroadcastAudience::All->value]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Textarea::make('message')
                    ->label('Xabar matni')
                    ->required()
                    ->maxLength(4096)
                    ->rows(5),

                FileUpload::make('image_path')
                    ->label('Rasm (ixtiyoriy)')
                    ->image()
                    ->disk('public')
                    ->directory('broadcasts')
                    ->visibility('public')
                    ->maxSize(5120)
                    ->helperText('Qo\'shilsa, xabar rasm ostidagi izoh (caption) sifatida yuboriladi.'),

                Radio::make('audience_type')
                    ->label('Auditoriya')
                    ->options([
                        BroadcastAudience::All->value => BroadcastAudience::All->label(),
                        BroadcastAudience::District->value => BroadcastAudience::District->label(),
                    ])
                    ->descriptions([
                        BroadcastAudience::All->value => "Botga hech bo'lmaganda bir marta /start bosgan barchasi (ro'yxatdan to'liq o'tmaganlar ham kiradi).",
                    ])
                    ->default(BroadcastAudience::All->value)
                    ->live()
                    ->required(),

                Select::make('district_ids')
                    ->label('Tumanlar')
                    ->multiple()
                    ->options(fn () => District::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->helperText('Xabar shu tuman(lar)da kamida bitta manzili bor foydalanuvchilarga yuboriladi.')
                    ->visible(fn (Get $get) => $get('audience_type') === BroadcastAudience::District->value)
                    ->required(fn (Get $get) => $get('audience_type') === BroadcastAudience::District->value),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $data = $this->form->getState();

        $result = app(BroadcastService::class)->send(
            message: $data['message'],
            imagePath: $data['image_path'] ?? null,
            audience: BroadcastAudience::from($data['audience_type']),
            districtIds: $data['district_ids'] ?? [],
            creator: auth('admin')->user(),
        );

        $this->form->fill(['audience_type' => BroadcastAudience::All->value]);
        $this->resetTable();

        Notification::make()
            ->title("Navbatga qo'yildi: {$result['target_count']} ta foydalanuvchiga yuboriladi")
            ->success()
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Broadcast::query())
            ->defaultSort('created_at', 'desc')
            ->poll('3s')
            ->columns([
                TextColumn::make('created_at')->label('Sana')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('message')->label('Xabar')->limit(60)->wrap(),
                TextColumn::make('audience_type')->label('Auditoriya')
                    ->formatStateUsing(fn (Broadcast $record) => $record->audience_type === BroadcastAudience::All
                        ? $record->audience_type->label()
                        : $record->audience_type->label().': '.($record->districtNames() ?: '—')),
                TextColumn::make('sent_count')->label('Yuborildi')->badge()->color('success'),
                TextColumn::make('failed_count')->label('Xato')
                    ->badge()->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('creator.name')->label('Yuborgan')->placeholder('—'),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Hali xabarnoma yuborilmagan');
    }
}
