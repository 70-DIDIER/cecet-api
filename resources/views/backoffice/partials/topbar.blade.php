<header class="navbar-custom">
    <div class="navbar-left">
        <button class="btn-desktop-toggle d-none d-xl-flex align-items-center justify-content-center me-3"
                id="desktop-sidebar-toggle" type="button" aria-label="Réduire le menu">
            <i class="bi bi-chevron-bar-left"></i>
        </button>
        <button class="sidebar-toggle-btn me-2" id="sidebar-toggle" type="button" aria-label="Afficher le menu">
            <i class="bi bi-list"></i>
        </button>
        <span class="navbar-environment d-none d-md-inline">@yield('title', 'Tableau de bord')</span>
    </div>

    <div class="navbar-actions ms-auto">
        <button class="navbar-action-btn me-1" id="btn-fullscreen" type="button" aria-label="Plein écran">
            <i class="bi bi-arrows-fullscreen"></i>
        </button>

        <div class="dropdown ms-2">
            <button class="navbar-profile-btn dropdown-toggle" type="button" data-bs-toggle="dropdown"
                    aria-expanded="false" id="profile-dropdown">
                <img src="{{ asset('vendor/spark/images/avatar.png') }}" alt="" class="navbar-profile-img">
                <span class="navbar-profile-name d-none d-md-inline">{{ $currentUser->full_name }}</span>
                <i class="bi bi-chevron-down navbar-profile-caret"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-profile" aria-labelledby="profile-dropdown">
                <li class="dropdown-header">{{ $currentUserRole }}</li>
                <li>
                    <a class="dropdown-item" href="{{ route('backoffice.step-up', ['return' => request()->getRequestUri()]) }}">
                        <i class="bi bi-shield-check"></i> Confirmer mon identité
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form action="{{ route('backoffice.logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-box-arrow-right"></i> Se déconnecter
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
