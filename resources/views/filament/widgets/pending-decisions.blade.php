<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">À traiter</x-slot>
        <x-slot name="description">
            {{ $total === 0 ? 'Rien n\'attend de décision pour le moment.' : $total.' élément'.($total > 1 ? 's' : '').' en attente de décision' }}
        </x-slot>

        <div class="cect-tiles">
            @foreach ($items as $item)
                <div class="cect-tile">
                    <p class="cect-tile-label">{{ $item['label'] }}</p>
                    <p class="cect-tile-value">{{ $item['count'] }}</p>
                    <p class="cect-tile-hint">{{ $item['hint'] }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
