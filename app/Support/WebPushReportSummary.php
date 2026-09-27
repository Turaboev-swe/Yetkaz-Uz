<?php

namespace App\Support;

use Minishlink\WebPush\MessageSentReport;

/**
 * Web Push yuborish natijasini (MessageSentReport) inson o'qiydigan
 * ko'rinishga keltiradi — log (LogWebPushReport) va `push:test` uchun umumiy.
 */
final class WebPushReportSummary
{
    /**
     * @return array{success: bool, status: int|null, host: string, reason: string, hint: string|null}
     */
    public static function from(MessageSentReport $report): array
    {
        $status = $report->getResponse()?->getStatusCode();

        return [
            'success' => $report->isSuccess(),
            'status' => $status,
            'host' => (string) (parse_url($report->getEndpoint(), PHP_URL_HOST) ?: '?'),
            'reason' => $report->getReason(),
            'hint' => self::hint($report->isSuccess(), $status),
        ];
    }

    private static function hint(bool $success, ?int $status): ?string
    {
        if ($success) {
            return null;
        }

        return match (true) {
            $status === null => 'javob yo\'q — tarmoq xatosi yoki timeout (push xizmatiga ulanib bo\'lmadi)',
            $status === 403 => 'VAPID mos kelmaydi — qayta obuna kerak',
            in_array($status, [404, 410], true) => 'obuna eskirgan — bazadan o\'chirildi, qayta obuna kerak',
            $status === 413 => 'xabar hajmi juda katta',
            $status === 429 => 'push xizmati limitga tushirdi (429)',
            $status >= 500 => 'push xizmatida vaqtinchalik xato',
            default => null,
        };
    }
}
