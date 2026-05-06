<x-app-layout>
    @php
        $user = $user ?? auth()->user();
        $employee = $employee ?? ($user?->employee ?? $user?->employeeByStaffId);
        $dashboardModules = collect($modules ?? dashboardModules());
        $accessibleModules = $user ? $user->getAccessibleModules() : [];
        $canUseLetters = in_array('letters', $accessibleModules, true);
        $initials = collect(preg_split('/\s+/', trim($employee?->full_name ?? $user?->full_name ?? 'User')) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => strtoupper(substr($part, 0, 1)))
            ->join('') ?: 'U';
    @endphp

    <div class="dashboard-shell">
        <header class="dashboard-topbar" x-data="{ userOpen: false }">
            <div class="dashboard-brand">
                <img src="{{ asset('images/gwlnew.png') }}" alt="GWL Logo">
                <span>GWL Mini Portal</span>
            </div>

            <div class="dashboard-actions">
                <livewire:notifications.general-bell />

                @if ($canUseLetters)
                    <livewire:letters.notifications />
                @endif

                <button type="button" class="tb-icon-btn" x-on:click="toggleTheme()" x-bind:aria-label="darkMode ? 'Use light mode' : 'Use dark mode'">
                    <svg x-show="! darkMode" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="12" cy="12" r="4" />
                        <path stroke-linecap="round" d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                    </svg>
                    <svg x-show="darkMode" x-cloak width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A8.5 8.5 0 1 1 11.2 3a6.5 6.5 0 0 0 9.8 9.8Z" />
                    </svg>
                </button>

                <div class="tb-user-menu" x-on:click.outside="userOpen = false">
                    <button type="button" class="tb-profile dashboard-profile" x-on:click="userOpen = ! userOpen" aria-label="Open account menu">
                        <span class="tb-av">{{ $initials }}</span>
                        <span class="tb-meta">
                            <span class="tb-name">{{ $employee?->full_name ?? $user?->full_name ?? 'User' }}</span>
                            <span class="tb-sub">{{ $roleName ?? $user?->roles?->pluck('display_name')->join(', ') }}</span>
                        </span>
                    </button>

                    <div class="tb-user-dropdown" x-show="userOpen" x-transition x-cloak>
                        <a href="{{ route('profile.edit') }}">Profile</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit">Sign Out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="dashboard-main">
            <section class="dashboard-greeting">
                <h1>
                    {{ $greeting ?? dashboardGreeting() }},
                    {{ $firstName ?? explode(' ', $employee?->full_name ?? 'User')[0] }}
                </h1>
                <p>
                    {{ $today ?? now()->format('l, j F Y') }}
                    <span>{{ $location ?? trim(($employee?->district?->district_name ?? '') . ', ' . ($employee?->region?->region_name ?? ''), ', ') }}</span>
                    <span>{{ $roleName ?? $user?->roles?->pluck('display_name')->join(', ') }}</span>
                </p>
            </section>

            <section class="dashboard-area" x-data="{ ready: false }" x-init="requestAnimationFrame(() => ready = true)">
                <template x-if="! ready">
                    <div class="dashboard-grid dashboard-grid-skeleton">
                        @for ($i = 0; $i < 3; $i++)
                            <div class="dashboard-card skeleton-card">
                                <span class="skeleton-line short"></span>
                                <span class="skeleton-line"></span>
                                <span class="skeleton-line"></span>
                            </div>
                        @endfor
                    </div>
                </template>

                <template x-if="ready">
                    <div class="dashboard-grid dashboard-grid-loaded">
                        @foreach ($dashboardModules as $module)
                            @php
                                $slug = $module['slug'] ?? '';
                                $icon = $module['icon'] ?? strtoupper(substr((string) ($module['title'] ?? 'M'), 0, 2));
                            @endphp
                            <a href="{{ $module['route'] }}" class="dashboard-card {{ $slug === 'leave' ? 'primary' : '' }}">
                                <div class="dashboard-card-top">
                                    <span class="dashboard-module-icon">{{ $icon }}</span>
                                    <span class="dashboard-badge">{{ $module['badge'] ?? ($slug === 'leave' ? 'Everyone' : 'Authorized') }}</span>
                                </div>

                                <h2>{{ $module['title'] }}</h2>
                                <p>{{ $module['description'] }}</p>
                            </a>
                        @endforeach
                    </div>
                </template>
            </section>
        </main>
    </div>
</x-app-layout>
