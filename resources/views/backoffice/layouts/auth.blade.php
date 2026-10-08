<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Connexion') — Back-office CECT</title>
    <link rel="icon" href="{{ asset('vendor/spark/images/favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('vendor/spark/libs/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/spark/libs/bootstrap-icons/bootstrap-icons.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/spark/css/main.css') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/css/cect.css') }}">
</head>
<body>
<div class="login-wrapper">
    <div class="login-bg-shape login-bg-shape-1"></div>
    <div class="login-bg-shape login-bg-shape-2"></div>

    <div class="login-card">
        <a href="{{ route('backoffice.login') }}" class="login-brand text-decoration-none">
            <i class="bi bi-asterisk"></i>
            <span>CECT</span>
        </a>

        @if (session('status'))
            <div class="alert alert-success py-2 small" role="status">{{ session('status') }}</div>
        @endif
        @if (session('warning'))
            <div class="alert alert-warning py-2 small" role="alert">{{ session('warning') }}</div>
        @endif

        @yield('content')

        <p class="login-footer-text">Accès réservé au personnel de la coopérative.</p>
    </div>
</div>
<script src="{{ asset('vendor/spark/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('admin-assets/js/auth.js') }}"></script>
</body>
</html>
