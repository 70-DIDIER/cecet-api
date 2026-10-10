<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">État de la plateforme</x-slot>
        <x-slot name="description">Contrôles automatiques sur la dernière heure</x-slot>

        @php
            $labels = [
                'suspicious_payments' => 'Paiements suspects',
                'payout_failures' => 'Versements en échec',
                'outbox_dead' => 'Messages abandonnés',
                'outbox_backlog' => 'Relais des messages',
                'failed_jobs' => 'Tâches en échec',
                'webhook_rejections' => 'Webhooks rejetés',
                'snapshot_failed' => 'Dernier snapshot',
            ];
        @endphp

        <ul class="cect-list">
            @foreach ($checks as $check)
                <li class="cect-list-row">
                    <div>
                        <p class="cect-list-title">{{ $labels[$check['code']] ?? $check['code'] }}</p>
                        @unless ($check['ok'])
                            <p class="cect-list-hint">{{ $check['message'] }}</p>
                        @endunless
                    </div>
                    <x-filament::badge :color="$check['ok'] ? 'success' : 'danger'">
                        {{ $check['ok'] ? 'Aucune alerte' : 'À examiner' }}
                    </x-filament::badge>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
