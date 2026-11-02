<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingCalculator;
use App\Services\Billing\InvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Restoranlar uchun oylik obuna hisob-kitoblarini yaratadi/yangilaydi.
 *
 *   php artisan billing:generate-invoices                  (o'tgan oy, Asia/Tashkent)
 *   php artisan billing:generate-invoices --period=2026-11
 *
 * Idempotent: qayta ishga tushirilsa to'lanmagan/qisman hisoblarni yangilaydi, `paid` va
 * `waived` hisoblarga tegmaydi; tarifsiz va test restoranlarni o'tkazib yuboradi.
 */
class GenerateBillingInvoices extends Command
{
    protected $signature = 'billing:generate-invoices {--period= : Oy, YYYY-MM (standart: o\'tgan oy)}';

    protected $description = 'Restoranlar uchun oylik obuna hisob-kitoblarini yaratish/yangilash';

    public function handle(InvoiceService $invoices): int
    {
        $option = $this->option('period');

        if ($option !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $option)) {
            $this->error("Noto'g'ri --period: «{$option}». Format: YYYY-MM (masalan 2026-11).");

            return self::FAILURE;
        }

        $period = $option !== null
            ? BillingCalculator::monthStart((string) $option)
            : CarbonImmutable::now(BillingCalculator::timezone())->startOfMonth()->subMonth()->startOfDay();

        $stats = $invoices->generateAll($period);

        $this->info(sprintf(
            '%s: yaratildi %d, yangilandi %d, o‘tkazib yuborildi %d.',
            BillingCalculator::monthLabel($period),
            $stats['created'],
            $stats['updated'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
