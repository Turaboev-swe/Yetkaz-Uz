<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Umumiy reyting</x-slot>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">O'rtacha reyting</div>
                <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">
                    {{ $average !== null ? number_format($average, 1) : '—' }}
                </div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Jami baho</div>
                <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $count }}</div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Mijozlarga ko'rinishi</div>
                <div class="mt-1 text-xl font-semibold {{ $isPublic ? 'text-success-600 dark:text-success-400' : 'text-gray-500' }}">
                    {{ $isPublic ? 'Ochiq' : 'Yashirin' }}
                </div>
            </div>
        </div>

        @unless ($isPublic)
            <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                Reyting hozircha mijozlarga ochiq ko'rsatilmayapti. Kamida <b>{{ $minCount }} ta baho</b> va
                <b>{{ number_format($minAvg, 1) }} o'rtacha</b> kerak — hozir {{ $count }} ta baho{{ $average !== null ? ', o\'rtacha '.number_format($average, 1) : '' }}.
                Shu shartgacha restoran "Yangi" deb ko'rsatiladi.
            </p>
        @endunless
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
