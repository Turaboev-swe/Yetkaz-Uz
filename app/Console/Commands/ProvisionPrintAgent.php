<?php

namespace App\Console\Commands;

use App\Enums\PosType;
use App\Models\Restaurant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Restoranni ESC/POS print agent uchun tayyorlaydi:
 *   - pos_type = escpos
 *   - print_agent_token — yo'q bo'lsa (yoki --rotate bilan) yangi 40-belgi tasodifiy
 *
 * Token EKRANGA CHIQMAYDI. --out berilsa faylga yoziladi (0600), aks holda faqat bazada.
 *
 *   php artisan print-agent:provision "Donix" --out=/root/donix-print-agent-token.txt
 *   php artisan print-agent:provision 4 --rotate --out=/root/donix-print-agent-token.txt
 */
class ProvisionPrintAgent extends Command
{
    protected $signature = 'print-agent:provision
        {restaurant : Restoran ID yoki aniq nomi}
        {--rotate : Token allaqachon bor bo\'lsa ham yangisini generatsiya qilish}
        {--out= : Tokenni shu faylga yozish (0600). Berilmasa faqat bazaga.}';

    protected $description = 'Restoranni ESC/POS print agent uchun sozlaydi (pos_type + token)';

    public function handle(): int
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

        // --out yo'lini bazaga tegishдан oldin tekshiramiz (yarim holat qolmasin).
        $out = $this->option('out');
        if ($out !== null && $out !== '' && ! is_writable(is_dir($out) ? $out : dirname($out))) {
            $this->error("Faylga yozib bo'lmaydi: {$out}");

            return self::FAILURE;
        }

        $changes = [];

        if ($restaurant->pos_type !== PosType::EscPos) {
            $changes[] = "pos_type: {$restaurant->pos_type->value} → escpos";
            $restaurant->pos_type = PosType::EscPos;
        }

        $rotate = (bool) $this->option('rotate');
        $hadToken = filled($restaurant->print_agent_token);

        if (! $hadToken || $rotate) {
            $restaurant->print_agent_token = Str::random(40);
            $changes[] = $hadToken ? 'print_agent_token: yangilandi (rotate)' : 'print_agent_token: yaratildi';
        }

        if ($changes !== []) {
            $restaurant->save();
        }

        if ($out !== null && $out !== '') {
            file_put_contents($out, $restaurant->print_agent_token.PHP_EOL);
            @chmod($out, 0600);
            $this->line("Token yozildi: <info>{$out}</info> (0600)");
        }

        $this->newLine();
        $this->table(['Maydon', 'Qiymat'], [
            ['restaurant_id', (string) $restaurant->id],
            ['name', $restaurant->name],
            ['pos_type', $restaurant->pos_type->value],
            ['Reverb kanali', "private-restaurant.{$restaurant->id}.print"],
            ['token holati', $hadToken && ! $rotate ? 'mavjud (o\'zgarmadi)' : ($hadToken ? 'yangilandi' : 'yaratildi')],
            ['token uzunligi', (string) strlen((string) $restaurant->print_agent_token)],
        ]);

        $this->newLine();
        if ($changes === []) {
            $this->info('Hech narsa o\'zgarmadi — restoran allaqachon sozlangan.');
        } else {
            $this->info('Bajarildi: '.implode('; ', $changes));
        }

        $this->line('Agent .env: RESTAURANT_ID='.$restaurant->id.'  (PRINT_AGENT_TOKEN — yuqoridagi fayldan)');

        return self::SUCCESS;
    }
}
