<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingCalculator;
use App\Services\Billing\InvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Restoranlar uchun oylik obuna hisob-kitoblarini yaratadi/yangilaydi.
 *
 *   php artisan billing:generate-invoices                  (o'tgan va joriy oy, Asia/Tashkent)
 *   php artisan billing:generate-invoices --period=2026-11
 *
 * Fixed tarif OLDINDAN to'lanadi: M oyi hisobi M ning 1-kunidan. Percent KEYIN to'lanadi:
 * M oyi hisobi faqat oy tugagach (savdo bo'yicha); tugamagan oy uchun percent o'tkazib yuboriladi
 * va xabar chiqadi. Argumentsiz (scheduler, har oyning 1-kuni): o'tgan oy (percent) va joriy oy (fixed).
 *
 * Idempotent: qayta ishga tushirilsa to'lanmagan/qisman hisoblarni snapshot tarifi bilan
 * yangilaydi, `paid` va `waived` hisoblarga tegmaydi; tarifsiz va test restoranlarni o'tkazib yuboradi.
 */
class GenerateBillingInvoices extends Command
{
    protected $signature = 'billing:generate-invoices {--period= : Oy, YYYY-MM (standart: o\'tgan va joriy oy)}';

    protected $description = 'Restoranlar uchun oylik obuna hisob-kitoblarini yaratish/yangilash (fixed — oldindan, percent — oy tugagach)';

    public function handle(InvoiceService $invoices): int
    {
        $option = $this->option('period');

        if ($option !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $option)) {
            $this->error("Noto'g'ri --period: «{$option}». Format: YYYY-MM (masalan 2026-11).");

            return self::FAILURE;
        }

        $current = CarbonImmutable::now(BillingCalculator::timezone())->startOfMonth()->startOfDay();

        $periods = $option !== null
            ? [BillingCalculator::monthStart((string) $option)]
            : [$current->subMonth(), $current];

        foreach ($periods as $period) {
            $stats = $invoices->generateAll($period);

            $this->info(sprintf(
                '%s: yaratildi %d, yangilandi %d, o‘tkazib yuborildi %d.',
                BillingCalculator::monthLabel($period),
                $stats['created'],
                $stats['updated'],
                $stats['skipped'],
            ));

            if ($stats['pending'] > 0) {
                $this->warn(sprintf(
                    '%s: %d ta foiz (percent) tarifidagi restoran o‘tkazib yuborildi — oy hali tugamagan, hisob oy tugagach yaratiladi.',
                    BillingCalculator::monthLabel($period),
                    $stats['pending'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
