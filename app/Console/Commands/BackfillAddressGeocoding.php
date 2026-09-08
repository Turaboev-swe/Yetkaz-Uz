<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Services\Geo\AddressGeocoder;
use Illuminate\Console\Command;

/**
 * Eski manzillarda `resolved_address` bo'sh bo'lsa — Nominatim reverse orqali
 * to'ldiradi. Nominatim foydalanish qoidasi: sekundiga 1 ta so'rov.
 *
 *   php artisan addresses:backfill-geocoding
 */
class BackfillAddressGeocoding extends Command
{
    protected $signature = 'addresses:backfill-geocoding {--limit=0 : Faqat shuncha manzil (0 = hammasi)}';

    protected $description = 'Manzillarga resolved_address ni Nominatim orqali to\'ldirish (1 so\'rov/sekund)';

    public function handle(AddressGeocoder $geocoder): int
    {
        $query = Address::query()->whereNull('resolved_address')->with('district')->orderBy('id');

        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('Barcha manzillarda resolved_address bor — ish yo\'q.');

            return self::SUCCESS;
        }

        $this->info("{$total} ta manzil to'ldiriladi (~".ceil($total / 60).' daqiqa)...');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $filled = 0;
        $missed = 0;

        foreach ($query->cursor() as $i => $address) {
            if ($i > 0) {
                sleep(1); // Nominatim: sekundiga 1 so'rov
            }

            $resolved = $geocoder->resolve((float) $address->lat, (float) $address->lng);

            if ($resolved !== null) {
                $address->forceFill(['resolved_address' => $resolved])->save();
                $filled++;
            } else {
                $missed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Tayyor: {$filled} ta to'ldirildi".($missed > 0 ? ", {$missed} ta Nominatim javob bermadi (zaxira ishlatiladi)" : '').'.');

        return self::SUCCESS;
    }
}
