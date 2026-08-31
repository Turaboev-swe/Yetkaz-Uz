<?php

namespace App\Console\Commands;

use Database\Seeders\AndijanGeoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Tuman markaz koordinatalarini OpenStreetMap (Nominatim) dan tekshiradi.
 *
 *   php artisan geo:fetch-coordinates            — faqat solishtiradi
 *   php artisan geo:fetch-coordinates --write     — AndijanGeoSeeder ni yangilaydi
 *
 * Nominatim qoidasi: soniyada 1 so'rov, User-Agent majburiy (config/geo.php).
 */
class FetchGeoCoordinates extends Command
{
    protected $signature = 'geo:fetch-coordinates
        {--write : Topilgan koordinatalarni AndijanGeoSeeder ga yozadi}
        {--threshold=3 : Necha km farqdan boshlab jadvalga chiqarish}';

    protected $description = 'Tuman markaz koordinatalarini Nominatim (OpenStreetMap) dan tekshiradi/yangilaydi';

    private const SETTLEMENT_TYPES = ['city', 'town', 'village', 'hamlet', 'municipality', 'suburb'];

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');
        $seeder = new AndijanGeoSeeder;
        $bounds = config('geo.region_bounds.AN');

        $diffs = [];   // [name, eski, yangi, km, izoh]
        $manual = [];  // [name, sabab, nomzodlar]
        $updates = []; // name => [lat, lng]

        $this->newLine();
        $this->info('Nominatim dan koordinata olinmoqda (soniyada 1 so\'rov)…');
        $bar = $this->output->createProgressBar(count($seeder->districts));

        foreach ($seeder->districts as $d) {
            $results = $this->lookup("{$d['center']}, Andijon viloyati, O'zbekiston")
                ?: $this->lookup("{$d['center']}, Andijan Region, Uzbekistan");

            $bar->advance();

            if (! app()->runningUnitTests()) {
                sleep(1); // Nominatim: soniyada 1 so'rov
            }

            if ($results === null) {
                $manual[] = [$d['name'], 'Tarmoq xatosi — so\'rov bajarilmadi', ''];

                continue;
            }

            // Aholi punktlari + (oxirgi chora) ma'muriy chegara markazi.
            // jsonv2: kalit `category` (format=json da `class`).
            $places = array_values(array_filter($results, function ($r) {
                if ($this->typeRank($r) > 0) {
                    return true;
                }
                $cat = $r['category'] ?? $r['class'] ?? '';

                return $cat === 'boundary' && ($r['type'] ?? '') === 'administrative';
            }));

            if ($places === []) {
                $manual[] = [$d['name'], 'Nominatim mos natija qaytarmadi ('.count($results).' ta)', ''];

                continue;
            }

            // Tumani (county) nomi mos kelganlar — turli tumandagi bir xil nomli
            // qishloqlarni chetlatadi.
            $inCounty = array_values(array_filter(
                $places,
                fn ($r) => $this->sameName(
                    $r['address']['county'] ?? $r['address']['state_district'] ?? '',
                    $d['name'],
                ),
            ));
            $countyMatched = $inCounty !== [];
            $pool = $countyMatched ? $inCounty : $places;

            // shahar/shaharcha markaz hisoblanadi — qishloqdan oldin.
            usort($pool, fn ($a, $b) => [$this->typeRank($b), (float) ($b['importance'] ?? 0)]
                <=> [$this->typeRank($a), (float) ($a['importance'] ?? 0)]);

            $best = $pool[0];
            $lat = round((float) $best['lat'], 7);
            $lng = round((float) $best['lon'], 7);
            $dist = $this->haversine($d['lat'], $d['lng'], $lat, $lng);

            $inBounds = $lat >= $bounds['lat'][0] && $lat <= $bounds['lat'][1]
                && $lng >= $bounds['lng'][0] && $lng <= $bounds['lng'][1];

            // Bir xil darajali (ikkalasi ham shahar yoki ikkalasi ham qishloq)
            // ikkinchi nomzod bir-biridan uzoqda bo'lsa — noaniq.
            $ambiguous = isset($pool[1])
                && $this->typeRank($pool[1]) === $this->typeRank($best)
                && $this->typeRank($best) > 0
                && $this->haversine((float) $best['lat'], (float) $best['lon'], (float) $pool[1]['lat'], (float) $pool[1]['lon']) > $threshold;

            $candidates = implode('  |  ', array_map(
                fn ($p) => sprintf('%.4f,%.4f %s/%s', $p['lat'], $p['lon'], $p['type'] ?? '?',
                    $p['address']['county'] ?? '-'),
                array_slice($pool, 0, 3),
            ));

            if (! $inBounds) {
                $manual[] = [$d['name'], sprintf('Natija chegaradan tashqarida: %.4f, %.4f', $lat, $lng), $candidates];

                continue;
            }

            if ($dist > $threshold) {
                $diffs[] = [
                    $d['name'],
                    sprintf('%.4f, %.4f', $d['lat'], $d['lng']),
                    sprintf('%.4f, %.4f', $lat, $lng),
                    sprintf('%.1f', $dist),
                    $ambiguous ? 'noaniq — yozilmaydi' : '',
                ];
            }

            if ($ambiguous) {
                // Noaniqlarni --write o'zgartirmaydi — qo'lda hal qilinadi.
                $manual[] = [$d['name'], 'Bir nechta ishonchli nomzod', $candidates];

                continue;
            }

            $updates[$d['name']] = [$lat, $lng];
        }

        $bar->finish();
        $this->newLine(2);

        $this->renderDiffs($diffs, $threshold);
        $this->renderManual($manual);

        if ($this->option('write')) {
            if ($updates === []) {
                $this->warn('Yoziladigan hech narsa yo\'q.');
            } else {
                $this->writeSeeder($updates);
                $this->info(count($updates).' ta tuman koordinatasi AndijanGeoSeeder ga yozildi.');
                $this->line('Keyin: <comment>php artisan migrate:fresh --seed</comment>');
            }
        } elseif ($diffs !== []) {
            $this->newLine();
            $this->line('Yozish uchun: <comment>php artisan geo:fetch-coordinates --write</comment>');
        }

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>>|null  null — tarmoq xatosi */
    private function lookup(string $query): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => config('geo.nominatim.user_agent')])
                ->timeout(20)
                ->retry(2, 2000)
                ->get(rtrim(config('geo.nominatim.base_url'), '/').'/search', [
                    'q' => $query,
                    'countrycodes' => 'uz',
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => 5,
                ]);
        } catch (\Throwable $e) {
            return null;
        }

        return $response->successful() ? $response->json() : null;
    }

    /** Uzbek apostroflarini (ʻ ʼ ' ‘ ’ `) birlashtirib, kichik harfda solishtiradi. */
    private function sameName(string $a, string $b): bool
    {
        $norm = fn (string $s) => trim(preg_replace('/\s+/u', ' ',
            str_replace(['ʻ', 'ʼ', 'ʹ', '‘', '’', '`', '´'], "'", mb_strtolower($s))));

        return $norm($a) !== '' && $norm($a) === $norm($b);
    }

    /** @param array<string, mixed> $place */
    private function typeRank(array $place): int
    {
        return match ($place['type'] ?? '') {
            'city' => 3,
            'town' => 2,
            'municipality' => 2,
            'village' => 1,
            default => 0,
        };
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @param array<int, array{0:string,1:string,2:string,3:string,4:string}> $diffs */
    private function renderDiffs(array $diffs, float $threshold): void
    {
        if ($diffs === []) {
            $this->info("Barcha koordinatalar {$threshold} km ichida — o'zgarish shart emas.");

            return;
        }

        $this->warn("{$threshold} km dan ortiq farq qilgan tumanlar:");
        $this->table(['Tuman', 'Hozirgi', 'Nominatim', 'Farq (km)', 'Izoh'], $diffs);
    }

    /** @param array<int, array{0:string,1:string,2:string}> $manual */
    private function renderManual(array $manual): void
    {
        if ($manual === []) {
            return;
        }

        $this->newLine();
        $this->error('QO\'LDA HAL QILINADIGANLAR (noaniq / topilmadi):');
        $this->table(['Tuman', 'Sabab', 'Nomzodlar'], $manual);
    }

    /** @param array<string, array{0:float,1:float}> $updates */
    private function writeSeeder(array $updates): void
    {
        $path = database_path('seeders/AndijanGeoSeeder.php');
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $i => $line) {
            if (! preg_match("/'name' => (?:'([^']*)'|\"([^\"]*)\")/", $line, $m)) {
                continue;
            }
            $name = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            if (! isset($updates[$name])) {
                continue;
            }

            [$lat, $lng] = $updates[$name];
            $lines[$i] = preg_replace(
                ["/'lat' => -?[0-9.]+/", "/'lng' => -?[0-9.]+/"],
                ["'lat' => {$lat}", "'lng' => {$lng}"],
                $line,
            );
        }

        file_put_contents($path, implode("\n", $lines)."\n");
    }
}
