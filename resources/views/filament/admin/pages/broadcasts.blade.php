<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Yangi xabarnoma</x-slot>

        <form wire:submit="send">
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button type="submit">
                    Yuborish
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Xabarnomalar tarixi</x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
