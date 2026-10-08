@if (session('status') || session('warning') || $errors->any())
    <div class="flash-area">
        @if (session('status'))
            <div class="alert alert-success d-flex align-items-center gap-2" role="status">
                <i class="bi bi-check-circle"></i> <span>{{ session('status') }}</span>
            </div>
        @endif
        @if (session('warning'))
            <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <span>{{ session('warning') }}</span>
            </div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <div class="fw-semibold mb-1"><i class="bi bi-x-octagon"></i> Action impossible</div>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
