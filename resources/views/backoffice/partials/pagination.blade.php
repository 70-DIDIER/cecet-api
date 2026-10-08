@if ($paginator->hasPages())
    <nav aria-label="Pagination" class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="small text-muted-green">
            Page {{ $paginator->currentPage() }} sur {{ $paginator->lastPage() }} — {{ $paginator->total() }} résultat(s)
        </span>
        <div class="btn-group">
            @if ($paginator->onFirstPage())
                <span class="btn btn-sm btn-outline-secondary disabled">Précédent</span>
            @else
                <a class="btn btn-sm btn-outline-dark" href="{{ $paginator->previousPageUrl() }}" rel="prev">Précédent</a>
            @endif

            @if ($paginator->hasMorePages())
                <a class="btn btn-sm btn-outline-dark" href="{{ $paginator->nextPageUrl() }}" rel="next">Suivant</a>
            @else
                <span class="btn btn-sm btn-outline-secondary disabled">Suivant</span>
            @endif
        </div>
    </nav>
@endif
