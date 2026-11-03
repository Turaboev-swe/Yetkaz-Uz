@php
    use App\Services\Dispatch\EscPos;
    use App\Services\Dispatch\ReceiptFormatter;

    // Mijoz yozgan matnlardan emoji olib tashlanadi (termoprinterda dog' bo'ladi); harflar o'z holicha.
    $p = fn (?string $s): string => EscPos::stripPictographs((string) $s);
    $t = fn (string $key): string => __("messages.receipt.{$key}");
    $amount = fn (int $tiyin): string => ReceiptFormatter::amount($tiyin);
    $money = fn (int $tiyin): string => $amount($tiyin).' '.$t('currency');

    $customer = $p($d->customerName);
    $hasAddress = $d->addressText !== null && ($d->addressText !== '' || $d->addressExtra !== '');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $t('title') }} {{ $d->orderNumber }}</title>
    <style>
        /* Termoprinter roligi: kenglik restoran sozlamasidan (58/80 mm), balandlik chek uzunligiga qarab.
           DIQQAT: Chrome/Edge `size: <kenglik> auto` ni (balandlik "auto") rad etadi va printerning o'z qog'oz
           o'lchamini ishlatadi — shuning uchun bir martalik Ctrl+P sozlamasida roll qog'ozni (80 mm) tanlash shart.
           Chek matni kengligi (.receipt) printer bosa oladigan maydondan ataylab torroq: chet kesilmasin. */
        @page { size: {{ $paperMm }}mm auto; margin: 0; }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        body {
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            font-size: 12px;
            line-height: 1.2;
            font-weight: 400;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Hech bir qator kenglikdan oshmasin: uzun matn o'raladi, qatorlar (flex/grid) torayadi yoki yangi qatorga tushadi. */
        .receipt { width: {{ $printableMm }}mm; margin: 0 auto; padding: 1mm 0 6mm; color: #000; overflow-wrap: anywhere; }
        .receipt * { min-width: 0; }
        .center { text-align: center; }
        .small { font-size: 11px; }

        .brand { font-size: 14px; font-weight: 700; letter-spacing: 1px; line-height: 1.15; }
        .restaurant { font-size: 12px; font-weight: 700; }

        .head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; column-gap: 2mm; margin-top: 3px; }
        .order-no { font-size: 18px; font-weight: 700; line-height: 1.1; }
        .type { padding: 0 3px; border: 1px solid #000; font-size: 10px; font-weight: 700; letter-spacing: .3px; white-space: nowrap; }
        .meta { font-size: 11px; margin-top: 1px; }

        hr { border: 0; border-top: 1px dashed #000; margin: 3px 0; }

        /* Taom: chapda "2x Nom" (uzun nom o'raladi), o'ngda qator jami — narxlar bir ustunda tekis. */
        .item { display: grid; grid-template-columns: minmax(0, 1fr) auto; column-gap: 2mm; margin: 1px 0; }
        .item-price { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .item-note { padding-left: 4mm; font-size: 11px; }

        .row { display: flex; justify-content: space-between; align-items: baseline; column-gap: 2mm; font-size: 11px; }
        .row > span:last-child { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }

        .total { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; column-gap: 2mm; margin-top: 2px; }
        .total-label { font-size: 11px; font-weight: 700; }
        .total-sum { margin-left: auto; font-size: 16px; font-weight: 700; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .pay { font-size: 10px; }

        .footer { display: flex; align-items: center; column-gap: 2mm; margin-top: 4px; }
        .qr { flex: none; width: {{ $qrMm }}mm; height: {{ $qrMm }}mm; }
        .qr svg { display: block; width: 100%; height: 100%; }
        .bot { font-size: 11px; font-weight: 700; }
        .scan { font-size: 10px; line-height: 1.15; }

        /* Oldindan ko'rish (brauzerda to'g'ridan-to'g'ri ochilganda): qog'ozga o'xshash varaq. */
        @media screen {
            body { background: #d1d5db; }
            .sheet { width: fit-content; margin: 16px auto; padding: 0 3mm; background: #fff; box-shadow: 0 2px 12px rgba(0, 0, 0, .25); }
            .bar { max-width: 90mm; margin: 12px auto 0; padding: 0 3mm; font: 600 13px Arial, sans-serif; color: #374151; display: flex; gap: 12px; align-items: center; justify-content: space-between; }
            .bar button { font: 700 14px Arial, sans-serif; padding: 8px 16px; border: 0; border-radius: 8px; background: #111827; color: #fff; cursor: pointer; }
        }
        @media print {
            .no-print { display: none !important; }
            .receipt { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="bar no-print">
        <span>{{ $t('preview_hint') }}</span>
        <button type="button" onclick="window.print()">{{ $t('print_button') }}</button>
    </div>

    <div class="sheet">
        <div class="receipt">
            <div class="center">
                <div class="brand">{{ $t('brand') }}</div>
                <div class="restaurant">{{ $p($d->restaurantName) }}</div>
            </div>

            <div class="head">
                <span class="order-no">{{ $d->orderNumber }}</span>
                <span class="type">{{ $d->pickup ? $t('type_pickup') : $t('type_delivery') }}</span>
            </div>
            <div class="meta">
                @if ($d->whenShort !== null){{ $d->whenShort }}@endif
                @if ($d->etaMinutes) · {{ $t('ready') }} ~{{ $d->etaMinutes }} {{ $t('minutes_short') }}@endif
            </div>

            <hr>

            @foreach ($d->items as $item)
                <div class="item">
                    <div>
                        <b>{{ $item['qty'] }}x</b> {{ $p($item['name']) }}
                        @if ($item['note'] !== null)
                            <div class="item-note">! {{ $p($item['note']) }}</div>
                        @endif
                    </div>
                    @if ($item['total'] !== null)
                        <div class="item-price">{{ $amount($item['total']) }}</div>
                    @endif
                </div>
            @endforeach

            @if ($d->hasBreakdown())
                <hr>
                <div class="row"><span>{{ $t('items_total') }}:</span><span>{{ $amount($d->itemsTotal) }}</span></div>
                @if ($d->discount > 0)
                    <div class="row"><span>{{ $t('discount') }}:</span><span>-{{ $amount($d->discount) }}</span></div>
                @endif
                @if ($d->deliveryFee > 0)
                    <div class="row"><span>{{ $t('delivery') }}:</span><span>{{ $amount($d->deliveryFee) }}</span></div>
                @endif
            @endif
            @if ($d->deliveryPaidToTaxi)
                <div class="small">{{ $t('taxi_note') }}</div>
            @endif

            <hr>

            <div class="total">
                <span class="total-label">{{ $t('to_collect') }}:</span>
                <span class="total-sum">{{ $money($d->total) }}</span>
            </div>
            <div class="pay">{{ $t('payment') }}: {{ $t('payment_'.$d->paymentKey()) }}</div>

            <hr>

            @if ($d->note !== null)
                <div class="small"><b>{{ $t('note') }}:</b> {{ $p($d->note) }}</div>
            @endif

            <div class="small">
                @if ($customer !== '')
                    <b>{{ $customer }}</b> · {{ $d->phone !== '' ? $d->phone : '-' }}
                @else
                    <b>{{ $t('phone') }}:</b> {{ $d->phone !== '' ? $d->phone : '-' }}
                @endif
            </div>
            @if ($d->telegramPhone !== null)
                <div class="small">{{ $t('phone_telegram') }}: {{ $d->telegramPhone }}</div>
            @endif

            @if ($hasAddress)
                <div class="small"><b>{{ $t('address') }}:</b> {{ $p($d->addressText) }}@if ($d->addressExtra !== ''), {{ $p($d->addressExtra) }}@endif</div>
            @endif

            <hr>

            <div class="footer">
                <div class="qr">{!! $qrSvg !!}</div>
                <div>
                    <div class="bot">{{ '@'.$botUsername }}</div>
                    <div class="scan">{{ $t('scan') }}</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
