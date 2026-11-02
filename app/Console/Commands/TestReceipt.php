<?php

namespace App\Console\Commands;

use App\Events\PrintJobRequested;
use App\Models\Restaurant;
use App\Services\Dispatch\ReceiptFormatter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sinov cheki: restoranning print agentiga namunaviy chek yuboradi (haqiqiy buyurtma yaratmaydi).
 * Chekda uzun taom nomi, kirill, emoji va ' bor — qog'oz kengligi, qatorlarga bo'linish va kodlash
 * (kirill -> lotin, emoji olib tashlangan) to'g'ri ishlashini printerda ko'zda tekshirish uchun.
 *
 *   php artisan print-agent:test-receipt "Donix"
 *   php artisan print-agent:test-receipt 4 --table        (+ 0x80-0xFF kodlash jadvali)
 *   php artisan print-agent:test-receipt 4 --dry          (yubormasdan faqat matnni ko'rsatish)
 */
class TestReceipt extends Command
{
    protected $signature = 'print-agent:test-receipt
        {restaurant : Restoran ID yoki aniq nomi}
        {--table : Chek oxiriga kodlash jadvali (0x80-0xFF xom baytlar) qo\'shish}
        {--dry : Agentga yubormaslik, faqat matnni ko\'rsatish}';

    protected $description = 'Print agentga qog\'oz kengligi va kodlashni tekshiruvchi namunaviy chek yuboradi';

    public function handle(ReceiptFormatter $formatter): int
    {
        $key = (string) $this->argument('restaurant');

        $restaurant = Restaurant::query()
            ->withoutGlobalScopes()
            ->when(ctype_digit($key), fn ($q) => $q->whereKey((int) $key), fn ($q) => $q->where('name', $key))
            ->first();

        if ($restaurant === null) {
            $this->error("Restoran topilmadi: «{$key}»");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');

        if (! $dry && ! $restaurant->usesPrintAgent()) {
            $this->error("«{$restaurant->name}» restoranida pos_type = escpos emas (hozir: {$restaurant->pos_type->value}). Avval print-agent:provision.");

            return self::FAILURE;
        }

        $order = $formatter->sampleOrder($restaurant);
        $receipt = $formatter->format($order, diagnostics: true, codepageTable: (bool) $this->option('table'));

        $this->line("Restoran : {$restaurant->name} (#{$restaurant->id})");
        $this->line("Qog'oz   : {$restaurant->receiptPaperMm()} mm, {$restaurant->receiptColumns()} belgi");
        $this->line("Kodlash  : ESC t {$restaurant->receiptCodepage()}");
        $this->newLine();
        $this->line($receipt['text']);

        if ($dry) {
            $this->comment('--dry: agentga yuborilmadi.');

            return self::SUCCESS;
        }

        if (! $restaurant->printAgentOnline()) {
            $this->warn('Printer agenti hozir o\'chiq ('.match ($restaurant->printAgentStatus()) {
                'never' => 'hech qachon ulanmagan',
                default => 'oxirgi signal: '.$restaurant->print_agent_last_seen_at?->diffForHumans(),
            }.'). Sinov cheki navbatga olinmaydi — agent ulanmagan bo\'lsa chek yo\'qoladi.');
        }

        try {
            // Salbiy id: haqiqiy buyurtmaga to'g'ri kelmaydi; agent tasdig'i 404 olib "tugadi" deb hisoblaydi.
            PrintJobRequested::dispatch(
                -(int) now()->format('His'),
                $restaurant->id,
                $order->order_number,
                base64_encode($receipt['escpos']),
                $receipt['text'],
            );
        } catch (Throwable $e) {
            $this->error('Agentga yuborib bo\'lmadi (Reverb?): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sinov cheki yuborildi: {$order->order_number}");

        return self::SUCCESS;
    }
}
