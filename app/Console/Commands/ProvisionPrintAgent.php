<?php

namespace App\Console\Commands;

use App\Enums\PosType;
use App\Models\Restaurant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Restoranni ESC/POS print agent uchun tayyorlaydi:
 *   - pos_type = escpos
 *   - print_agent_token — yo'q bo'lsa (yoki --rotate bilan) yangi 40-belgi tasodifiy
 *
 * Token o'zi hech qachon oddiy chiqishga (stdout) tushmaydi, faqat:
 *   --out=FILE  — faylga yoziladi (0600); yo'l shu buyruq ishlaydigan JOYда yozilishi kerak
 *   --stdout    — RAW token stdout'ga (boshqa hammasi stderr'ga). Redirect uchun:
 *                   docker compose ... exec -T app php artisan print-agent:provision "Donix" --stdout \
 *                     > /root/donix-print-agent-token.txt && chmod 600 ...
 *
 *   php artisan print-agent:provision "Donix" --out=storage/app/donix-token.txt
 *   php artisan print-agent:provision 4 --rotate --stdout > /root/donix-print-agent-token.txt
 */
class ProvisionPrintAgent extends Command
{
    protected $signature = 'print-agent:provision
        {restaurant : Restoran ID yoki aniq nomi}
        {--rotate : Token allaqachon bor bo\'lsa ham yangisini generatsiya qilish}
        {--out= : Tokenni shu faylga yozish (0600)}
        {--stdout : RAW token stdout\'ga (diagnostika stderr\'ga) — faylga redirect uchun}';

    protected $description = 'Restoranni ESC/POS print agent uchun sozlaydi (pos_type + token)';

    public function handle(): int
    {
        $stdoutMode = (bool) $this->option('stdout');
        // --stdout rejimida barcha diagnostika stderr'ga — stdout'да faqat token qoladi.
        $err = fn (string $line) => $stdoutMode
            ? $this->output->getErrorStyle()->writeln($line)
            : $this->line($line);

        $key = (string) $this->argument('restaurant');

        $restaurant = Restaurant::query()
            ->withoutGlobalScopes()
            ->when(ctype_digit($key), fn ($q) => $q->whereKey((int) $key), fn ($q) => $q->where('name', $key))
            ->first();

        if ($restaurant === null) {
            $this->output->getErrorStyle()->writeln("<error>Restoran topilmadi: «{$key}»</error>");

            return self::FAILURE;
        }

        // --out yo'lini bazaga tegishдan oldin tekshiramiz (yarim holat qolmasin).
        $out = $this->option('out');
        if ($out !== null && $out !== '' && ! is_writable(is_dir($out) ? $out : dirname($out))) {
            $this->output->getErrorStyle()->writeln("<error>Faylga yozib bo'lmaydi: {$out}</error>");

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

        $token = (string) $restaurant->print_agent_token;

        if ($out !== null && $out !== '') {
            file_put_contents($out, $token.PHP_EOL);
            @chmod($out, 0600);
            $err("Token yozildi: {$out} (0600)");
        }

        $err('');
        $err("restaurant_id : {$restaurant->id}");
        $err("name          : {$restaurant->name}");
        $err("pos_type      : {$restaurant->pos_type->value}");
        $err("Reverb kanali : private-restaurant.{$restaurant->id}.print");
        $err('token         : '.($hadToken && ! $rotate ? 'mavjud (o\'zgarmadi)' : ($hadToken ? 'yangilandi' : 'yaratildi')).', '.strlen($token).' belgi');
        $err('');
        $err($changes === [] ? 'Hech narsa o\'zgarmadi — allaqachon sozlangan.' : 'Bajarildi: '.implode('; ', $changes));
        $err("Agent .env: RESTAURANT_ID={$restaurant->id}");

        if ($stdoutMode) {
            // Faqat token, dekoratsiyasiz — redirect faylга toza tushsin.
            $this->output->writeln($token, OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }
}
