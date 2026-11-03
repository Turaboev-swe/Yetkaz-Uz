<?php

namespace App\Console\Commands;

use App\Services\Stock\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Kunlik qoldiqni tiklaydi: `daily_reset_time` (Asia/Tashkent) kelgan mahsulotlarda
 * `stock_quantity = daily_stock`. Scheduler har daqiqa chaqiradi; har mahsulot kuniga BIR marta tiklanadi
 * (scheduler bir daqiqa o'tkazib yuborsa ham shu kun ichida keyingi daqiqada tiklanadi).
 */
class ResetDailyStock extends Command
{
    protected $signature = 'stock:reset-daily';

    protected $description = 'Kunlik qoldiqni (daily_stock) vaqti kelgan mahsulotlarda tiklaydi';

    public function handle(StockService $stock): int
    {
        $ids = $stock->resetDaily();

        if ($ids !== []) {
            Log::info('[stock] kunlik qoldiq tiklandi', ['products' => $ids]);
            $this->info('Kunlik qoldiq tiklandi: '.count($ids).' ta mahsulot.');
        }

        return self::SUCCESS;
    }
}
