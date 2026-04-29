<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased erp-body">
        <div class="erp-shell">
            <div class="topbar">
                <div class="topbar-left">
                    <a href="{{ route('dashboard') }}" class="tb-logo" aria-label="Dashboard home">
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="#85B7EB"><rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="2" width="5" height="5" rx="1"/><rect x="2" y="9" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1"/></svg>
                    </a>
                    <span class="tb-title">GWL ERP Portal</span>
                    <span class="tb-sep">/</span>
                    <span class="tb-page">{{ $navigation['currentModule']['title'] }}</span>
                </div>

                <div class="tb-right">
                    @if (($module ?? null) === 'letters')
                        <livewire:letters.notifications />
                    @endif

                    <a href="{{ route('dashboard') }}" class="tb-back">
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="currentColor"><path d="M6 2L3 5l3 3"/></svg>
                        Home
                    </a>

                    <a href="{{ route('profile.edit') }}" class="tb-profile">
                        <span class="tb-av">{{ $navigation['identity']['initials'] }}</span>
                        <span class="tb-meta">
                            <span class="tb-name">{{ $navigation['identity']['name'] }}</span>
                            <span class="tb-sub">{{ $navigation['identity']['role'] }}</span>
                        </span>
                    </a>
                </div>
            </div>

            <div class="module-tabs">
                @foreach ($navigation['modules'] as $moduleTab)
                    <a
                        href="{{ $moduleTab['route'] }}"
                        class="tab {{ $moduleTab['active'] ? 'active' : '' }}"
                    >
                        {{ $moduleTab['title'] }}
                    </a>
                @endforeach
            </div>

            <div class="erp-layout">
                <aside class="sidebar">
                    <div class="sb-module">
                        <div class="sb-mod-name">{{ $navigation['currentModule']['title'] }}</div>
                        <div class="sb-mod-sub">
                            {{ $navigation['identity']['role'] }}
                            &middot;
                            {{ $navigation['identity']['location'] }}
                        </div>
                    </div>

                    <div class="sb-nav">
                        @foreach ($navigation['sidebar'] as $item)
                            @if (($item['type'] ?? 'item') === 'section')
                                <div class="sb-section">{{ $item['label'] }}</div>
                            @else
                                <a href="{{ $item['url'] }}" class="sb-item {{ $item['is_active'] ? 'active' : '' }}">
                                    {!! $item['icon'] !!}
                                    <span>{{ $item['label'] }}</span>
                                </a>
                            @endif
                        @endforeach
                    </div>

                    <div class="sb-footer">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="sb-item sb-item-quiet">
                                <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M9 2h4a1 1 0 011 1v10a1 1 0 01-1 1H9v-1h4V3H9V2z"/><path d="M7 4l1.4 1.4L6.8 7H12v2H6.8l1.6 1.6L7 12 3 8l4-4z"/></svg>
                                <span>Sign Out</span>
                            </button>
                        </form>
                    </div>
                </aside>

                <main class="erp-main">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @livewireScripts
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    </body>
</html>
