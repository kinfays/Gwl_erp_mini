<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    x-data="{
        darkMode: document.documentElement.classList.contains('dark'),
        toggleTheme() {
            this.darkMode = ! this.darkMode;
            localStorage.setItem('gwl-theme', this.darkMode ? 'dark' : 'light');
            document.documentElement.classList.toggle('dark', this.darkMode);
        }
    }"
    x-bind:class="{ dark: darkMode }"
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'GWL ERP Portal' }}</title>

        <script>
            (() => {
                const preference = localStorage.getItem('gwl-theme');
                const dark = preference
                    ? preference === 'dark'
                    : window.matchMedia('(prefers-color-scheme: dark)').matches;

                document.documentElement.classList.toggle('dark', dark);
            })();
        </script>

        {{-- Preconnect for performance --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles

        {{-- Optional: CSP nonce for inline scripts if needed --}}
        {{-- <meta http-equiv="Content-Security-Policy" content="script-src 'nonce-{{ $nonce }}'"> --}}
    </head>
    <body class="font-sans antialiased erp-body">
        @php
            // Extract navigation data to variables for cleaner templates
            $identity = $navigation['identity'] ?? [];
            $currentModule = $navigation['currentModule'] ?? [];
            $modules = $navigation['modules'] ?? [];
            $sidebar = $navigation['sidebar'] ?? [];
        @endphp

        <div class="erp-shell" x-data="{ sidebarOpen: false, userOpen: false }">
            {{-- Top Navigation Bar --}}
            <header class="topbar" role="banner">
                <div class="topbar-left">
                    <button type="button" class="tb-menu-btn" x-on:click="sidebarOpen = true" aria-label="{{ __('Open menu') }}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>

                    <a href="{{ route('dashboard') }}" class="tb-logo" aria-label="{{ __('Dashboard home') }}">
                        {{-- Logo image with performance optimizations --}}
                        <img
                            src="{{ asset('images/gwlnew.png') }}"
                            alt="{{ __('GWL Logo') }}"
                            class="tb-logo-img"
                            loading="lazy"
                            decoding="async"
                            width="32"
                            height="32"
                        >
                    </a>
                    <span class="tb-title">{{ __('GWL Staff Portal') }}</span>
                    <span class="tb-sep" aria-hidden="true">/</span>
                    <span class="tb-page">{{ $currentModule['title'] ?? '' }}</span>
                </div>

                <div class="tb-right">
                    <livewire:notifications.general-bell />

                    @if (($module ?? null) === 'letters')
                        <livewire:letters.notifications />
                    @endif

                    <button type="button" class="tb-icon-btn" x-on:click="toggleTheme()" x-bind:aria-label="darkMode ? '{{ __('Use light mode') }}' : '{{ __('Use dark mode') }}'">
                        <svg x-show="! darkMode" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <circle cx="12" cy="12" r="4" />
                            <path stroke-linecap="round" d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                        </svg>
                        <svg x-show="darkMode" x-cloak width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A8.5 8.5 0 1 1 11.2 3a6.5 6.5 0 0 0 9.8 9.8Z" />
                        </svg>
                    </button>

                    <a href="{{ route('dashboard') }}" class="tb-back" aria-label="{{ __('Go to home page') }}">
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="currentColor" aria-hidden="true">
                            <path d="M6 2L3 5l3 3"/>
                        </svg>
                        <span>{{ __('Home') }}</span>
                    </a>

                    <div class="tb-user-menu" x-on:click.outside="userOpen = false">
                        <button type="button" class="tb-profile" x-on:click="userOpen = ! userOpen" aria-label="{{ __('Open account menu') }}">
                            <span class="tb-av" aria-hidden="true">{{ $identity['initials'] ?? '??' }}</span>
                            <span class="tb-meta">
                                <span class="tb-name">{{ $identity['name'] ?? '' }}</span>
                                <span class="tb-sub">{{ $identity['role'] ?? '' }}</span>
                            </span>
                        </button>

                        <div class="tb-user-dropdown" x-show="userOpen" x-transition x-cloak>
                            <a href="{{ route('profile.edit') }}">{{ __('Profile') }}</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit">{{ __('Sign Out') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            {{-- Module Navigation Tabs --}}
            <nav class="module-tabs" role="navigation" aria-label="{{ __('Main modules') }}">
                @foreach ($modules as $moduleTab)
                    <a
                        href="{{ $moduleTab['route'] ?? '#' }}"
                        class="tab {{ ($moduleTab['active'] ?? false) ? 'active' : '' }}"
                        @if ($moduleTab['active'] ?? false)
                            aria-current="page"
                        @endif
                    >
                        {{ $moduleTab['title'] ?? '' }}
                    </a>
                @endforeach
            </nav>

            {{-- Main Layout with Sidebar --}}
            <div class="erp-layout">
                <button type="button" class="sidebar-scrim" x-show="sidebarOpen" x-transition.opacity x-on:click="sidebarOpen = false" aria-label="{{ __('Close menu') }}" x-cloak></button>

                {{-- Sidebar Navigation --}}
                <aside class="sidebar" x-bind:class="{ 'open': sidebarOpen }" role="complementary" aria-label="{{ __('Sidebar navigation') }}">
                    <div class="sb-module">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">
                            <div>
                                <div class="sb-mod-name">{{ $currentModule['title'] ?? '' }}</div>
                                <div class="sb-mod-sub">
                                    {{ $identity['role'] ?? '' }}
                                    &middot;
                                    {{ $identity['location'] ?? '' }}
                                </div>
                            </div>
                            <button type="button" class="sb-close" x-on:click="sidebarOpen = false" aria-label="{{ __('Close menu') }}">
                                &times;
                            </button>
                        </div>
                    </div>

                    <nav class="sb-nav" role="navigation" aria-label="{{ __('Sidebar menu') }}">
                        @foreach ($sidebar as $item)
                            @if (($item['type'] ?? 'item') === 'section')
                                <div class="sb-section" role="presentation">{{ $item['label'] ?? '' }}</div>
                            @else
                                <a
                                    href="{{ $item['url'] ?? '#' }}"
                                    class="sb-item {{ ($item['is_active'] ?? false) ? 'active' : '' }}"
                                    @if ($item['is_active'] ?? false)
                                        aria-current="page"
                                    @endif
                                >
                                    {{-- Render icon safely --}}
                                    <span class="sb-icon" aria-hidden="true">{!! $item['icon'] ?? '' !!}</span>
                                    <span>{{ $item['label'] ?? '' }}</span>
                                </a>
                            @endif
                        @endforeach
                    </nav>

                    {{-- Sidebar Footer with Logout --}}
                    <div class="sb-footer">
                        <form method="POST" action="{{ route('logout') }}" class="sb-logout-form">
                            @csrf
                            <button type="submit" class="sb-item sb-item-quiet" aria-label="{{ __('Sign out of your account') }}">
                                <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                    <path d="M9 2h4a1 1 0 011 1v10a1 1 0 01-1 1H9v-1h4V3H9V2z"/>
                                    <path d="M7 4l1.4 1.4L6.8 7H12v2H6.8l1.6 1.6L7 12 3 8l4-4z"/>
                                </svg>
                                <span>{{ __('Sign Out') }}</span>
                            </button>
                        </form>
                    </div>
                </aside>

                {{-- Main Content Area --}}
                <main class="erp-main" role="main" tabindex="-1">
                    @if (session('status'))
                        <div class="alert alert-success" role="alert">
                            @switch (session('status'))
                                @case('profile-updated')
                                    {{ __('Profile updated.') }}
                                    @break
                                @case('password-updated')
                                    {{ __('Password updated.') }}
                                    @break
                                @case('verification-link-sent')
                                    {{ __('A new verification link has been sent.') }}
                                    @break
                                @default
                                    {{ session('status') }}
                            @endswitch
                        </div>
                    @endif

                    {{ $slot }}
                </main>
            </div>
        </div>

        <x-global.toast-center />
        <x-global.confirm-modal />

        @livewireScripts

        {{-- External Chart Library --}}
        <script src="https://cdn.jsdelivr.net/npm/apexcharts" defer></script>
    </body>
</html>
