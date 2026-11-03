<?php

namespace App\Console\Commands;

use App\Services\Billing\TariffService;
use Illuminate\Console\Command;

/**
 * `restaurants.billing_*` ni JORIY oy uchun amaldagi tarifga moslaydi (tarix bo'yicha), kelajak yozuviga emas.
 *
 *   php artisan billing:sync-tariffs
 *
 * Idempotent; tarix va hisob-kitoblarga tegmaydi. Oy almashganda kelajak yozuvi «joriy» bo'lishi uchun
 * har kuni 00:05 da (Asia/Tashkent) ishlaydi; production'dagi eski (kelajak yozuviga sinxronlangan) holatni
 * to'g'irlash uchun qo'lda ham ishga tushiriladi.
 */
class SyncBillingTariffs extends Command
{
    protected $signature = 'billing:sync-tariffs';

    protected $description = 'Restoranlarning joriy tarifini (billing_* ustunlari) tarix bo‘yicha joriy oyga moslash';

    public function handle(TariffService $tariffs): int
    {
        $this->info(sprintf('Joriy tarif sinxronlandi: o‘zgargan restoranlar — %d.', $tariffs->syncAllCurrentTariffs()));

        return self::SUCCESS;
    }
}
