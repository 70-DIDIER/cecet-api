<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Tableau de bord') — Back-office CECT</title>
    <link rel="icon" href="{{ asset('vendor/spark/images/favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('vendor/spark/libs/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/spark/libs/bootstrap-icons/bootstrap-icons.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/spark/libs/apexcharts/apexcharts.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/spark/libs/flatpickr/flatpickr.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/spark/css/main.css') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/css/cect.css') }}">
    @stack('styles')
</head>
<body>

@include('backoffice.partials.sidebar')

<div class="main-wrapper">
    @include('backoffice.partials.topbar')

    @include('backoffice.partials.flash')

    @yield('content')

    <footer class="footer-custom">
        <div class="footer-left">
            <span class="footer-logo"><i class="bi bi-asterisk"></i> CECT</span>
            <span>Back-office de la coopérative — interface basée sur
                <a href="https://themewagon.com/themes/spark-admin/" target="_blank" rel="noopener noreferrer">Spark Admin</a> (MIT)</span>
        </div>
    </footer>
</div>

<script src="{{ asset('vendor/spark/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
@stack('vendor-scripts')
<script src="{{ asset('admin-assets/js/backoffice.js') }}"></script>
@stack('scripts')
</body>
</html>
