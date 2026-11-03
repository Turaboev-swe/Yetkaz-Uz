@php
    use App\Services\Dispatch\EscPos;
    use App\Services\Dispatch\ReceiptFormatter;

    // Mijoz yozgan matnlardan emoji olib tashlanadi (termoprinterda dog' bo'ladi); harflar o'z holicha.
    $p = fn (?string $s): string => EscPos::stripPictographs((string) $s);
    $t = fn (string $key): string => __("messages.receipt.{$key}");
    $money = fn (int $tiyin): string => ReceiptFormatter::amount($tiyin).' '.$t('currency');
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
           Bosiladigan kenglik esa .receipt da aniq berilgan, qog'oz o'lchamiga bog'liq emas. */
        @page { size: {{ $paperMm }}mm auto; margin: 0; }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        body {
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            font-size: 13px;
            line-height: 1.25;
            font-weight: 600;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .receipt { width: {{ $printableMm }}mm; margin: 0 auto; padding: 3mm 0 10mm; color: #000; }
        .center { text-align: center; }
        .row { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; }
        .row > span:last-child { text-align: right; }
        .label { font-weight: 800; }

        /* Logo termoprinterda aniq chiqishi uchun: qora-oq va kontrast. Fayl almashtirilsa o'zi yangilanadi. */
        .brand img {
            display: block;
            width: {{ $logoWidthMm }}mm;
            max-width: 100%;
            height: auto;
            margin: 0 auto;
            filter: grayscale(100%) contrast(180%);
        }
        .brand-name { margin-top: 1mm; font-size: 22px; font-weight: 900; letter-spacing: 3px; }
        .restaurant { margin-top: 0.5mm; font-size: 16px; font-weight: 800; overflow-wrap: anywhere; }

        .type { margin: 2.5mm 0 1.5mm; padding: 1mm 0; border: 2px solid #000; font-size: 16px; font-weight: 900; letter-spacing: 1px; }
        .order-no { font-size: 36px; font-weight: 900; line-height: 1.1; letter-spacing: 1px; overflow-wrap: anywhere; }

        hr { border: 0; border-top: 1.5px dashed #000; margin: 2mm 0; }
        hr.solid { border-top: 2px solid #000; }

        .item { margin: 1mm 0; }
        .item-name { font-size: 15px; font-weight: 800; overflow-wrap: anywhere; }
        .item-note { padding-left: 4mm; font-weight: 700; overflow-wrap: anywhere; }
        .block { overflow-wrap: anywhere; }

        .total { margin-top: 1mm; }
        .total .total-label { font-size: 15px; font-weight: 900; }
        .total .total-sum { font-size: 30px; font-weight: 900; line-height: 1.15; }

        .footer { margin-top: 1mm; }
        .footer .thanks { font-size: 14px; font-weight: 800; }
        .qr { width: {{ $qrMm }}mm; height: {{ $qrMm }}mm; margin: 2mm auto 1mm; }
        .qr svg { display: block; width: 100%; height: 100%; }
        .footer .bot { font-size: 14px; font-weight: 900; }
        .footer .scan { font-size: 11px; font-weight: 600; }

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
            <div class="brand center">
                <img src="{{ $logoUrl }}" alt="">
                <div class="brand-name">{{ $t('brand') }}</div>
                <div class="restaurant">{{ $p($d->restaurantName) }}</div>
            </div>

            <div class="type center">*** {{ $d->pickup ? $t('type_pickup') : $t('type_delivery') }} ***</div>

            <div class="center">
                <div class="order-no">{{ $d->orderNumber }}</div>
            </div>

            <div class="row"><span class="label">{{ $t('time') }}:</span><span>{{ $d->when }}</span></div>
            @if ($d->etaMinutes)
                <div class="row"><span class="label">{{ $t('ready') }}:</span><span>~{{ $d->etaMinutes }} {{ $t('minutes_short') }}</span></div>
            @endif

            <hr>

            @foreach ($d->items as $item)
                <div class="item">
                    <div class="item-name"><b>{{ $item['qty'] }}x</b> {{ $p($item['name']) }}</div>
                    @if ($item['note'] !== null)
                        <div class="item-note">! {{ $p($item['note']) }}</div>
                    @endif
                </div>
            @endforeach

            <hr>

            @if ($d->note !== null)
                <div class="block"><span class="label">{{ $t('note') }}:</span> {{ $p($d->note) }}</div>
                <hr>
            @endif

            @if (trim($d->customerName) !== '')
                <div class="block"><span class="label">{{ $t('customer') }}:</span> {{ $p($d->customerName) }}</div>
            @endif
            <div class="block"><span class="label">{{ $t('phone') }}:</span> {{ $d->phone !== '' ? $d->phone : '-' }}</div>
            @if ($d->telegramPhone !== null)
                <div class="block"><span class="label">{{ $t('phone_telegram') }}:</span> {{ $d->telegramPhone }}</div>
            @endif

            @if ($d->addressText !== null)
                <hr>
                <div class="block">
                    <div class="label">{{ $t('address') }}:</div>
                    <div>{{ $p($d->addressText) }}</div>
                    @if ($d->addressExtra !== '')
                        <div>{{ $p($d->addressExtra) }}</div>
                    @endif
                </div>
            @endif

            <hr class="solid">

            @if ($d->discount > 0)
                <div class="row"><span class="label">{{ $t('discount') }}:</span><span>-{{ $money($d->discount) }}</span></div>
            @endif
            @if ($d->deliveryPaidToTaxi)
                <div class="block">{{ $t('taxi_note') }}</div>
            @endif

            <div class="total center">
                <div class="total-label">{{ $t('to_collect') }}</div>
                <div class="total-sum">{{ $money($d->total) }}</div>
            </div>

            <div class="center"><span class="label">{{ $t('payment') }}:</span> {{ $t('payment_'.$d->paymentKey()) }}</div>

            <hr>

            <div class="footer center">
                <div class="thanks">{{ $t('footer') }}</div>
                <div class="qr">{!! $qrSvg !!}</div>
                <div class="bot">{{ '@'.$botUsername }}</div>
                <div class="scan">{{ $t('scan') }}</div>
            </div>
        </div>
    </div>
</body>
</html>
