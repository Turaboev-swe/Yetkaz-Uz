<?php

namespace App\Telegram\Handlers;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * /id — chat ID ni ko'rsatadi. Restoran egasi buni panelда "Bildirishnoma
 * chat ID" maydoniga kiritadi (yangi buyurtmalar shu chatga keladi).
 */
class IdHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->sendMessage(
            "Sizning Telegram chat ID: <code>{$bot->chatId()}</code>\n\n"
            .'Buni Yetkaz restoran panelida «Bildirishnoma chat ID» maydoniga kiriting.',
            parse_mode: ParseMode::HTML,
        );
    }
}
