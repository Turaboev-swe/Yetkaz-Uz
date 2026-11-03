<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesKitchenOrders;
use App\Models\Restaurant;
use App\Services\Dispatch\ReceiptFormatter;
use App\Support\QrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Brauzer cheki (pos_type = browser): oshxona paneli yashirin iframe'da chek sahifasini ochib
 * `window.print()` chaqiradi. Ma'lumot ESC/POS cheki bilan bir manbadan (ReceiptFormatter::data).
 * Faqat shu restoran xodimlariga ochiladi (AuthorizesKitchenOrders).
 */
class KitchenReceiptController extends Controller
{
    use AuthorizesKitchenOrders;

    /** GET /kitchen/orders/{order}/receipt — chek sahifasi (oldindan ko'rish ham shu). Hech narsa yozmaydi. */
    public function show(ReceiptFormatter $formatter, int $orderId): Response
    {
        $order = $this->manageableOrder($this->staff(), $orderId);

        // Egasining RestaurantScope'i boshqa biriktirilgan restoranni yashirmasin.
        $restaurant = Restaurant::withoutGlobalScopes()->findOrFail($order->restaurant_id);
        $order->setRelation('restaurant', $restaurant);

        $botUsername = ltrim((string) config('print.browser.bot_username'), '@');

        return response()
            ->view('kitchen.receipt', [
                'd' => $formatter->data($order),
                'paperMm' => $restaurant->receiptPaperMm(),
                'printableMm' => $restaurant->receiptPrintableMm(),
                // Ildiz-nisbiy yo'l: APP_URL noto'g'ri bo'lsa ham (http/https, IP) logo shu domenning o'zidan yuklanadi.
                'logoUrl' => '/'.ltrim((string) config('print.browser.logo'), '/'),
                'logoWidthMm' => (int) config('print.browser.logo_width_mm'),
                'qrMm' => (int) config('print.browser.qr_mm'),
                'qrSvg' => QrCode::svg("https://t.me/{$botUsername}"),
                'botUsername' => $botUsername,
            ])
            // Chek har safar yangi ma'lumot bilan chiqsin (qayta chiqarishda eski nusxa kesh'dan olinmasin).
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * POST /kitchen/orders/{order}/printed — chop etish chaqirildi: `orders.printed_at` yoziladi (birinchi marta;
     * "qayta chiqarish" uni o'zgartirmaydi) va eski «Chek chiqmadi» belgisi tozalanadi. Faqat brauzer rejimidagi
     * restoran uchun: print agenti restoranlarida printed_at ni faqat agent tasdig'i yozadi.
     */
    public function printed(int $orderId): JsonResponse
    {
        $order = $this->manageableOrder($this->staff(), $orderId);

        $restaurant = Restaurant::withoutGlobalScopes()->findOrFail($order->restaurant_id);
        abort_unless($restaurant->usesBrowserPrint(), HttpResponse::HTTP_CONFLICT, 'Bu restoranda brauzer orqali chop etish yoqilmagan.');

        if ($order->printed_at === null) {
            $order->forceFill(['printed_at' => now(), 'dispatch_failed_at' => null])->save();
        }

        return response()->json(['data' => ['printed' => true, 'printed_at' => $order->printed_at?->toIso8601String()]]);
    }
}
