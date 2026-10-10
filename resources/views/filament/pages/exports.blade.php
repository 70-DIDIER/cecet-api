<x-filament-panels::page>
    <div class="cect-columns cect-columns-2">
        @foreach ($this->exports() as $export)
            <x-filament::section>
                <x-slot name="heading">{{ $export['title'] }}</x-slot>
                <p class="cect-muted" style="margin-bottom: 16px;">{{ $export['description'] }}</p>
                {{ $export['action'] }}
            </x-filament::section>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
