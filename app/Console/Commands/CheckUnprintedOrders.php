<?php

namespace App\Console\Commands;

use App\Services\Dispatch\PrintMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cheki `print.unprinted_after_seconds` ichida chiqmagan buyurtmalarni «Chek chiqmadi» deb belgilaydi
 * (har daqiqa, scheduler). Agent o'chiq / printer ishlamayotgan holatni ham ushlaydi.
 */
class CheckUnprintedOrders extends Command
{
    protected $signature = 'print:check-unprinted';

    protected $description = "Chek chiqmagan buyurtmalarni belgilaydi (oshxona paneliga ogohlantirish)";

    public function handle(PrintMonitor $monitor): int
    {
        $count = $monitor->flagUnprinted();

        if ($count > 0) {
            Log::warning('[print] chek chiqmadi deb belgilandi', ['orders' => $count]);
            $this->warn("Chek chiqmadi deb belgilandi: {$count} ta buyurtma.");
        }

        return self::SUCCESS;
    }
}
