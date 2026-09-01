<?php

namespace App\Services\Dispatch;

use App\Enums\DeliveryType;
use App\Models\Order;

/**
 * Buyurtmadan oshxona cheki — ESC/POS oqimi + o'qiladigan matn.
 */
class ReceiptFormatter
{
    /** @return array{escpos: string, text: string} */
    public function format(Order $order): array
    {
        $order->loadMissing('restaurant', 'user');

        $som = fn (int $tiyin): string => number_format(intdiv($tiyin, 100), 0, '.', ' ').' som';
        $type = $order->delivery_type === DeliveryType::Pickup ? 'OLIB KETISH' : 'YETKAZISH';
        $when = $order->created_at?->timezone(config('app.display_timezone'))->format('d.m.Y H:i');

        $p = (new EscPos)->init();

        $p->align('center')->bold(true)->size(1, 1)
            ->text($order->restaurant->name)
            ->size(0, 0)->bold(false);
        $p->align('center')->bold(true)->text("*** {$type} ***")->bold(false);
        $p->align('left')->rule('=');

        $p->columns('Buyurtma:', $order->order_number);
        $p->columns('Vaqt:', (string) $when);
        if ($order->eta_minutes) {
            $p->columns('Tayyor:', "~{$order->eta_minutes} daq");
        }
        $p->rule();

        foreach ($order->items as $it) {
            $p->bold(true)->text($it['qty'].'x  '.$it['name'])->bold(false);
            if (! empty($it['note'])) {
                $p->text('     ! '.$it['note']);
            }
        }
        $p->rule();

        if ($order->note) {
            $p->bold(true)->text('IZOH:')->bold(false);
            foreach ($this->wrap($order->note) as $line) {
                $p->text($line);
            }
            $p->rule();
        }

        if ($order->delivery_type !== DeliveryType::Pickup && $order->address_snapshot) {
            $snap = $order->address_snapshot;
            $p->bold(true)->text('MANZIL:')->bold(false);
            foreach ($this->wrap(trim(($snap['address_text'] ?? '').' '.($snap['district'] ?? ''))) as $line) {
                $p->text($line);
            }
            $extra = array_filter([
                ($snap['entrance'] ?? null) ? 'kirish '.$snap['entrance'] : null,
                ($snap['floor'] ?? null) ? 'qavat '.$snap['floor'] : null,
                ($snap['apartment'] ?? null) ? 'xonadon '.$snap['apartment'] : null,
            ]);
            if ($extra) {
                $p->text(implode(', ', $extra));
            }
            $p->text('Tel: '.($order->user->phone ?? '-'));
            $p->rule();
        }

        $p->bold(true)->size(0, 1)->columns('JAMI:', $som($order->total))->size(0, 0)->bold(false);
        $p->text('Tolov: naqd');
        $p->feed(1)->align('center')->text('yetkaz.uz')->feed(3)->cut();

        return [
            'escpos' => $p->raw(),
            'text' => $p->plain(),
        ];
    }

    /** @return array<int, string> */
    private function wrap(string $s): array
    {
        return explode("\n", wordwrap($s, EscPos::WIDTH, "\n", true));
    }
}
