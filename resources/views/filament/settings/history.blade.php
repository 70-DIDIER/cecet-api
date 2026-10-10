@php
    use App\Domain\Backoffice\Support\Format;
@endphp

<div>
    @forelse ($versions as $version)
        <div class="cect-list-row" style="align-items: flex-start;">
            <div>
                <p class="cect-list-title">
                    {{ $sensitive ? '••••••••' : (is_scalar($version->value) || $version->value === null ? var_export($version->value, true) : json_encode($version->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}
                </p>
                <p class="cect-list-hint">{{ $version->reason }}</p>
            </div>
            <p class="cect-list-hint" style="white-space: nowrap;">En vigueur le {{ Format::dateTime($version->effective_from) }}</p>
        </div>
    @empty
        <p class="cect-muted">Aucune version enregistrée : la valeur par défaut s'applique.</p>
    @endforelse
</div>
