@php use App\Support\Money; @endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">Xulosa — {{ $periodLabel }}</x-slot>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([
                ['Buyurtmalar', number_format($summary['orders'], 0, '.', ' ')],
                ['Yetkazilgan', number_format($summary['delivered'], 0, '.', ' ')],
                ['Bekor qilingan', number_format($summary['cancelled'], 0, '.', ' ')],
                ['Daromad', Money::soms($summary['revenue_tiyin'])],
                ["O'rtacha chek", Money::soms($summary['avg_check_tiyin'])],
                ['Mijozlar', number_format($summary['customers'], 0, '.', ' ')],
            ] as [$label, $value])
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Oshxona tezligi — {{ $periodLabel }}</x-slot>
        <x-slot name="description">O'rtacha vaqtlar. Manba: buyurtma status tarixi</x-slot>

        @if ($kitchen)
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ([
                    ['Qabul qilish', $kitchen[0]],
                    ['Tayyorlash', $kitchen[1]],
                    ['To\'liq yetkazish', $kitchen[2]],
                    ['Bekor qilingan', $kitchen[3]],
                    ['Chek xatosi', $kitchen[4]],
                ] as [$label, $value])
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-gray-500 dark:text-gray-400">Bu davrda buyurtma yo'q.</p>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Eng ko'p sotilgan taomlaringiz</x-slot>
        <x-slot name="headerEnd">
            <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="exportProducts">CSV</x-filament::button>
        </x-slot>

        <x-report-table
            :head="['Taom', 'Sotildi', 'Daromad']"
            :rows="$topProducts"
            empty="Bu davrda sotuv yo'q" />
    </x-filament::section>
</x-filament-panels::page>
