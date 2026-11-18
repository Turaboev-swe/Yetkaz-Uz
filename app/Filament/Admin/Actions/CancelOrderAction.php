<?php

namespace App\Filament\Admin\Actions;

use App\Models\Order;
use App\Models\Staff;
use App\Services\Ordering\OrderStatusService;
use Filament\Actions\Action as PageAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Validation\ValidationException;

/**
 * /admin → Buyurtmalar: platforma admini buyurtmani bekor qiladi (new | accepted | preparing; sabab majburiy).
 *
 * Mantiq YAGONA servisda — `OrderStatusService::cancel()`: tarix (`changed_by = admin:{id}`), mijozga mavjud bekor qilish
 * xabari (sabab bilan), oshxona paneli hodisasi va band qilingan qoldiqning qaytishi shu yerdan o'tadi. Bu klass faqat
 * UI (forma, ko'rinish, xato xabari); ruxsat server tomonida ham tekshiriladi (UI'da yashirish yetarli emas).
 */
final class CancelOrderAction
{
    /** Ko'rinish: faqat platform_admin va buyurtma hali bekor qilinadigan holatda. */
    public static function canCancel(Order $order): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof Staff
            && $staff->isPlatformAdmin()
            && app(OrderStatusService::class)->canCancel($order);
    }

    /** Buyurtma sahifasi (ViewOrder) uchun sarlavha amali. */
    public static function header(callable $record): PageAction
    {
        return PageAction::make('cancelOrder')
            ->label('Bekor qilish')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Buyurtmani bekor qilish')
            ->modalDescription('Mijozga sabab bilan xabar yuboriladi, band qilingan qoldiq qaytariladi.')
            ->modalSubmitActionLabel('Bekor qilish')
            ->form(self::form())
            ->visible(fn (): bool => self::canCancel($record()))
            ->action(fn (array $data): bool => self::run($record(), $data));
    }

    /** Buyurtmalar ro'yxati qatori uchun amal. */
    public static function row(): TableAction
    {
        return TableAction::make('cancelOrder')
            ->label('Bekor qilish')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Buyurtmani bekor qilish')
            ->modalDescription('Mijozga sabab bilan xabar yuboriladi, band qilingan qoldiq qaytariladi.')
            ->modalSubmitActionLabel('Bekor qilish')
            ->form(self::form())
            ->visible(fn (Order $record): bool => self::canCancel($record))
            ->action(fn (Order $record, array $data): bool => self::run($record, $data));
    }

    /** @return array<int, Textarea> */
    private static function form(): array
    {
        return [
            Textarea::make('reason')
                ->label('Bekor qilish sababi (mijozga ko‘rsatiladi)')
                ->required()
                ->maxLength(500)
                ->rows(3),
        ];
    }

    /**
     * @param  array{reason?: string}  $data
     * @return bool bekor qilindimi (holat o'zgarib bo'lgan bo'lsa — false va aniq xabar)
     */
    public static function run(Order $order, array $data): bool
    {
        $staff = Filament::auth()->user();
        abort_unless($staff instanceof Staff && $staff->isPlatformAdmin(), 403);

        try {
            app(OrderStatusService::class)->cancel($order, (string) ($data['reason'] ?? ''), "admin:{$staff->id}");
        } catch (ValidationException $e) {
            // Holat allaqachon o'zgargan (oshxona qabul qilgan / boshqa admin bekor qilgan) yoki bekor qilib bo'lmaydi.
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first() ?: 'Buyurtmani bekor qilib bo‘lmadi')->send();

            return false;
        }

        Notification::make()->success()->title("Buyurtma {$order->order_number} bekor qilindi")->send();

        return true;
    }
}
