<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\OrderStatus;
use App\Enums\PosType;
use App\Models\Restaurant;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * /admin Dashboard — chek printeri (print agent) bor restoranlar: agent tirikmi (heartbeat) va nechta
 * buyurtma cheki chiqmagan. Jim bo'lib qolgan agentlar yuqorida.
 */
class PrintAgentStatus extends TableWidget
{
    protected static ?int $sort = 1;

    protected static ?string $heading = 'Chek printerlari (print agent)';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Restaurant::query()->where('pos_type', PosType::EscPos->value)->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Restaurant::query()
                    ->where('pos_type', PosType::EscPos->value)
                    ->withCount(['orders as unprinted_active_count' => fn (Builder $q) => $q
                        ->withoutGlobalScopes()
                        ->whereNull('printed_at')
                        ->whereNotNull('dispatch_failed_at')
                        ->whereIn('status', OrderStatus::activeValues())])
                    // Jim agentlar birinchi: hech qachon ulanmagan, keyin eng uzoq jim, keyin onlayn.
                    ->orderByRaw('print_agent_last_seen_at IS NOT NULL, print_agent_last_seen_at ASC')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Restoran')->searchable(),
                Tables\Columns\TextColumn::make('agent_status')->label('Agent')->badge()
                    ->state(fn (Restaurant $r): string => $r->printAgentStatus())
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'online' => 'Onlayn',
                        'offline' => 'Printer agenti o‘chiq',
                        default => 'Hech qachon ulanmagan',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'online' => 'success',
                        'offline' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('print_agent_last_seen_at')->label('Oxirgi signal')
                    ->since()->placeholder('—'),
                Tables\Columns\TextColumn::make('unprinted_active_count')->label('Chek chiqmagan (faol)')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
            ])
            ->paginated(false)
            ->poll('30s');
    }
}
