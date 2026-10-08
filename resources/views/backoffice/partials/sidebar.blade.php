<div class="sidebar-wrapper" id="sidebar">
    <a href="{{ route('backoffice.dashboard') }}" class="sidebar-brand">
        <i class="bi bi-asterisk"></i>
        <span>CECT</span>
    </a>

    <div class="flex-grow-1 overflow-y-auto">
        @foreach ($menu as $section)
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-title">{{ $section['title'] }}</div>
                <ul class="sidebar-menu-list">
                    @foreach ($section['items'] as $item)
                        <li class="sidebar-menu-item">
                            <a href="{{ route($item['route']) }}"
                               class="sidebar-menu-link {{ request()->routeIs($item['active'].'*') ? 'active' : '' }}"
                               title="{{ $item['label'] }}">
                                <i class="bi {{ $item['icon'] }}"></i>
                                <span>{{ $item['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>

    <div class="sidebar-profile">
        <img src="{{ asset('vendor/spark/images/avatar.png') }}" alt="" class="sidebar-profile-img">
        <div class="sidebar-profile-info">
            <div class="sidebar-profile-name">{{ $currentUser->full_name }}</div>
            <div class="sidebar-profile-email">{{ $currentUserRole }}</div>
        </div>
    </div>
</div>
