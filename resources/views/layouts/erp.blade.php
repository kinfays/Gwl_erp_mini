<!--Bismillah
    Developed by
    FaisalEwuntomah (Faysysgh)
    GWL -->

    <!--ERP Portal Layout-->
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light dark">

        <title>{{ $title ?? 'GWL ERP Portal' }}</title>
        @include('partials.favicon')
        @include('partials.theme-script')

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="erp-body">
        @php
            $identity = $navigation['identity'] ?? [];
            $currentModule = $navigation['currentModule'] ?? [];
            $modules = $navigation['modules'] ?? [];
            $isHome = ($module ?? null) === 'home';

            // Keep a section heading only when at least one of its items survived the permission filter.
            $rawSidebar = array_values($navigation['sidebar'] ?? []);
            $sidebar = [];

            foreach ($rawSidebar as $index => $item) {
                if (($item['type'] ?? 'item') === 'section') {
                    $next = $rawSidebar[$index + 1] ?? null;

                    if ($next && ($next['type'] ?? 'item') !== 'section') {
                        $sidebar[] = $item;
                    }

                    continue;
                }

                $sidebar[] = $item;
            }

            // Pages without a module menu (home, profile) list the modules instead.
            if ($sidebar === []) {
                $sidebar[] = ['label' => __('Home'), 'url' => route('dashboard'), 'icon_name' => 'house', 'is_active' => $isHome];

                foreach ($modules as $moduleLink) {
                    if (filled($moduleLink['route'] ?? null)) {
                        $sidebar[] = [
                            'label' => $moduleLink['title'],
                            'url' => $moduleLink['route'],
                            'icon_name' => $moduleLink['icon_name'] ?? 'layout-dashboard',
                            'is_active' => false,
                        ];
                    }
                }
            }

            $activeItem = collect($sidebar)->first(
                fn (array $item) => ($item['type'] ?? 'item') !== 'section' && ($item['is_active'] ?? false)
            );
            $moduleTitle = $currentModule['title'] ?? null;
            $moduleUrl = $currentModule['route'] ?? null;
            $pageCrumb = $activeItem && filled($activeItem['route'] ?? null) && request()->routeIs($activeItem['route'])
                ? $activeItem['label']
                : ($title ?? null);

            $jumpItems = collect([['label' => __('Home'), 'group' => __('Portal'), 'url' => route('dashboard'), 'icon' => 'house']])
                ->merge(collect($modules)->filter(fn (array $item) => filled($item['route'] ?? null))->map(fn (array $item) => [
                    'label' => $item['title'],
                    'group' => __('Module'),
                    'url' => $item['route'],
                    'icon' => $item['icon_name'] ?? 'layout-dashboard',
                ]))
                ->merge(collect($sidebar)->filter(fn (array $item) => ($item['type'] ?? 'item') !== 'section' && ($item['url'] ?? '#') !== '#')->map(fn (array $item) => [
                    'label' => $item['label'],
                    'group' => $isHome ? __('Portal') : $moduleTitle,
                    'url' => $item['url'],
                    'icon' => $item['icon_name'] ?? 'circle',
                ]))
                ->push(['label' => __('Profile'), 'group' => __('Account'), 'url' => route('profile.edit'), 'icon' => 'user-round'])
                ->unique('url')
                ->values()
                ->all();
        @endphp

        <a href="#main-content" class="skip-link">{{ __('Skip to main content') }}</a>

        <div
            class="app-shell"
            x-data="erpShell"
            x-on:keydown.escape.window="closeDrawer()"
            x-on:resize.window.debounce.150ms="if (window.innerWidth >= 1024) closeDrawer()"
        >
            <header class="app-top">
                <button
                    type="button"
                    class="icon-btn app-menu-btn"
                    x-on:click="openDrawer()"
                    aria-controls="app-sidebar"
                    x-bind:aria-expanded="drawer.toString()"
                    aria-label="{{ __('Open navigation menu') }}"
                >
                    <x-ui.icon name="menu" />
                </button>

                <a href="{{ route('dashboard') }}" class="app-brand" aria-label="{{ __('GWL Staff Portal home') }}">
                    <img src="{{ asset('images/gwlnew.png') }}" alt="" class="app-brand-mark" width="32" height="32" decoding="async">
                    <span class="app-brand-text">
                        <span>{{ __('GWL Staff Portal') }}</span>
                        <small>{{ __('Ghana Water Limited') }}</small>
                    </span>
                </a>

                @if (count($modules) > 1)
                    <nav class="app-modules" aria-label="{{ __('Modules') }}">
                        @foreach ($modules as $moduleTab)
                            <a
                                href="{{ $moduleTab['route'] ?? '#' }}"
                                class="app-module"
                                title="{{ $moduleTab['title'] ?? '' }}"
                                @if ($moduleTab['active'] ?? false) aria-current="page" @endif
                            >{{ $moduleTab['short'] ?? ($moduleTab['title'] ?? '') }}</a>
                        @endforeach
                    </nav>
                @endif

                <div class="app-top-actions">
                    <button
                        type="button"
                        class="jump-trigger"
                        x-on:click="$dispatch('jump-open')"
                        aria-haspopup="dialog"
                        aria-keyshortcuts="Control+K Meta+K"
                        aria-label="{{ __('Jump to a page') }}"
                    >
                        <x-ui.icon name="search" class="icon-sm" />
                        <span class="jump-trigger-label">{{ __('Jump to a page…') }}</span>
                        <kbd>Ctrl K</kbd>
                    </button>

                    <livewire:notifications.general-bell />

                    @if (($module ?? null) === 'letters')
                        <livewire:letters.notifications />
                    @endif

                    <button
                        type="button"
                        class="icon-btn theme-btn"
                        x-on:click="$store.theme.toggle()"
                        aria-label="{{ __('Switch theme') }}"
                        x-bind:aria-label="$store.theme.dark ? '{{ __('Switch to light theme') }}' : '{{ __('Switch to dark theme') }}'"
                    >
                        <x-ui.icon name="moon" x-show="! $store.theme.dark" />
                        <x-ui.icon name="sun" x-show="$store.theme.dark" x-cloak />
                    </button>

                    <div
                        class="user-menu"
                        x-data="{ open: false }"
                        x-on:click.outside="open = false"
                        x-on:keydown.escape.stop="if (open) { open = false; $refs.button.focus() }"
                    >
                        <button
                            type="button"
                            class="user-btn"
                            x-ref="button"
                            x-on:click="open = ! open"
                            aria-haspopup="menu"
                            aria-controls="user-menu-panel"
                            x-bind:aria-expanded="open.toString()"
                            aria-label="{{ __('Account menu for :name', ['name' => $identity['name'] ?? __('your account')]) }}"
                        >
                            <span class="avatar" aria-hidden="true">{{ $identity['initials'] ?? '??' }}</span>
                            <x-ui.icon name="chevron-down" class="icon-sm" />
                        </button>

                        <div id="user-menu-panel" class="user-menu-panel" role="menu" x-show="open" x-transition.origin.top.right x-cloak>
                            <div class="user-menu-who" role="presentation">
                                <strong>{{ $identity['name'] ?? '' }}</strong>
                                <span>{{ $identity['role'] ?? '' }}</span>
                                <span>{{ $identity['location'] ?? '' }}</span>
                            </div>
                            <a href="{{ route('profile.edit') }}" role="menuitem">
                                <x-ui.icon name="user-round" />
                                {{ __('Profile') }}
                            </a>
                            <button type="button" class="user-menu-theme" role="menuitem" x-on:click="$store.theme.toggle()">
                                <x-ui.icon name="moon" x-show="! $store.theme.dark" />
                                <x-ui.icon name="sun" x-show="$store.theme.dark" x-cloak />
                                <span x-text="$store.theme.dark ? '{{ __('Light theme') }}' : '{{ __('Dark theme') }}'">{{ __('Dark theme') }}</span>
                            </button>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" role="menuitem">
                                    <x-ui.icon name="log-out" />
                                    {{ __('Sign Out') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <div class="app-body">
                <div class="app-scrim" x-show="drawer" x-transition.opacity x-on:click="closeDrawer()" x-cloak aria-hidden="true"></div>

                <aside
                    id="app-sidebar"
                    class="app-side"
                    x-bind:class="{ 'is-open': drawer }"
                    x-trap.inert.noscroll="drawer"
                    aria-label="{{ __('Sidebar') }}"
                >
                    <div class="side-head">
                        <a href="{{ route('dashboard') }}" class="app-brand" aria-label="{{ __('GWL Staff Portal home') }}">
                            <img src="{{ asset('images/gwlnew.png') }}" alt="" class="app-brand-mark" width="32" height="32" decoding="async">
                            <span class="app-brand-text" style="display:grid"><span>{{ __('GWL Staff Portal') }}</span></span>
                        </a>
                        <button type="button" class="icon-btn" x-on:click="closeDrawer()" aria-label="{{ __('Close navigation menu') }}">
                            <x-ui.icon name="x" />
                        </button>
                    </div>

                    <div class="side-who">
                        <span class="avatar" aria-hidden="true">{{ $identity['initials'] ?? '??' }}</span>
                        <strong>{{ $identity['name'] ?? '' }}</strong>
                        <span class="side-role">{{ $identity['role'] ?? '' }}</span>
                        <span class="side-loc">
                            <x-ui.icon name="map-pin" />
                            <span>{{ $identity['location'] ?? '' }}</span>
                        </span>
                    </div>

                    @if (count($modules) > 1 && ! $isHome)
                        <nav class="side-modules" aria-label="{{ __('Modules') }}">
                            @foreach ($modules as $moduleLink)
                                <a
                                    href="{{ $moduleLink['route'] ?? '#' }}"
                                    class="side-link"
                                    @if ($moduleLink['active'] ?? false) aria-current="page" @endif
                                >
                                    <x-ui.icon :name="$moduleLink['icon_name'] ?? 'layout-dashboard'" />
                                    <span class="side-label">{{ $moduleLink['title'] ?? '' }}</span>
                                </a>
                            @endforeach
                        </nav>
                    @endif

                    <nav class="side-nav" aria-label="{{ $isHome ? __('Modules') : ($moduleTitle ?? __('Sidebar menu')) }}">
                        @foreach ($sidebar as $item)
                            @if (($item['type'] ?? 'item') === 'section')
                                <div class="side-group" role="presentation">{{ $item['label'] ?? '' }}</div>
                            @else
                                <a
                                    href="{{ $item['url'] ?? '#' }}"
                                    class="side-link"
                                    data-tip="{{ $item['label'] ?? '' }}"
                                    @if ($item['is_active'] ?? false) aria-current="page" @endif
                                >
                                    <x-ui.icon :name="$item['icon_name'] ?? 'circle'" />
                                    <span class="side-label">{{ $item['label'] ?? '' }}</span>
                                    @if (($item['badge'] ?? 0) > 0)
                                        <span class="side-label side-count"><span class="sr-only-text">{{ __('Pending: ') }}</span>{{ $item['badge'] > 99 ? '99+' : $item['badge'] }}</span>
                                    @endif
                                </a>
                            @endif
                        @endforeach
                    </nav>

                    <div class="side-foot">
                        <button
                            type="button"
                            class="side-link side-collapse"
                            data-tip="{{ __('Expand sidebar') }}"
                            x-on:click="toggleCollapsed()"
                            x-bind:aria-pressed="collapsed.toString()"
                        >
                            <x-ui.icon name="panel-left-close" x-show="! collapsed" />
                            <x-ui.icon name="panel-left-open" x-show="collapsed" x-cloak />
                            <span class="side-label">{{ __('Collapse sidebar') }}</span>
                        </button>
                    </div>
                </aside>

                <main id="main-content" class="app-main erp-main" tabindex="-1">
                    @unless ($isHome)
                        <nav class="crumbs" aria-label="{{ __('Breadcrumb') }}">
                            <ol>
                                <li>
                                    <a href="{{ route('dashboard') }}">
                                        <x-ui.icon name="house" class="icon-sm" />
                                        <span class="sr-only-text">{{ __('Home') }}</span>
                                    </a>
                                </li>
                                @if ($moduleTitle && $moduleUrl)
                                    <li><a href="{{ $moduleUrl }}">{{ $moduleTitle }}</a></li>
                                @elseif ($moduleTitle && $moduleTitle !== $pageCrumb)
                                    <li><span>{{ $moduleTitle }}</span></li>
                                @endif
                                @if ($pageCrumb && $pageCrumb !== $moduleTitle)
                                    <li><span aria-current="page">{{ $pageCrumb }}</span></li>
                                @endif
                            </ol>
                        </nav>
                    @endunless

                    @if (session('status'))
                        <div class="alert alert-success" role="status">
                            <x-ui.icon name="circle-check" />
                            <span>
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
                            </span>
                        </div>
                    @endif

                    {{ $slot }}
                </main>
            </div>
        </div>

        <div
            x-data="jumpTo(@js(collect($jumpItems)->map(fn (array $item) => ['label' => $item['label'], 'group' => $item['group'], 'url' => $item['url']])->all()))"
            x-on:jump-open.window="show()"
            x-on:keydown.window.ctrl.k.prevent="show()"
            x-on:keydown.window.meta.k.prevent="show()"
        >
            <div class="jump-backdrop" x-show="open" x-transition.opacity x-on:click="hide()" x-cloak></div>
            <div
                class="jump-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="jump-title"
                x-show="open"
                x-trap.inert.noscroll="open"
                x-on:keydown.escape.prevent.stop="hide()"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-cloak
            >
                <h2 id="jump-title" class="sr-only-text">{{ __('Jump to a page') }}</h2>
                <div class="jump-field">
                    <x-ui.icon name="search" />
                    <input
                        type="text"
                        x-ref="input"
                        x-model="query"
                        x-on:input="active = 0"
                        x-on:keydown.arrow-down.prevent="move(1)"
                        x-on:keydown.arrow-up.prevent="move(-1)"
                        x-on:keydown.enter.prevent="go()"
                        role="combobox"
                        aria-expanded="true"
                        aria-controls="jump-results"
                        aria-autocomplete="list"
                        x-bind:aria-activedescendant="activeId"
                        aria-label="{{ __('Search pages and modules') }}"
                        placeholder="{{ __('Search pages and modules') }}"
                        autocomplete="off"
                        spellcheck="false"
                    >
                    <kbd>Esc</kbd>
                </div>
                <ul id="jump-results" class="jump-list" role="listbox" x-ref="list" aria-label="{{ __('Pages') }}">
                    @foreach ($jumpItems as $index => $jumpItem)
                        <li
                            id="jump-option-{{ $index }}"
                            class="jump-option"
                            role="option"
                            data-index="{{ $index }}"
                            x-show="isVisible({{ $index }})"
                            x-bind:aria-selected="isActive({{ $index }}).toString()"
                            x-on:mousemove="hover({{ $index }})"
                            x-on:click="go({{ $index }})"
                        >
                            <x-ui.icon :name="$jumpItem['icon']" />
                            <span>{{ $jumpItem['label'] }}</span>
                            <small>{{ $jumpItem['group'] }}</small>
                        </li>
                    @endforeach
                </ul>
                <p class="jump-empty" x-show="results.length === 0" x-cloak>
                    {{ __('No pages match') }} “<span x-text="query"></span>”
                </p>
                <div class="jump-foot" aria-hidden="true">
                    <span><kbd>↑</kbd><kbd>↓</kbd> {{ __('to move') }}</span>
                    <span><kbd>Enter</kbd> {{ __('to open') }}</span>
                    <span><kbd>Esc</kbd> {{ __('to close') }}</span>
                </div>
            </div>
        </div>

        <x-global.toast-center />
        <x-global.confirm-modal />

        @livewireScripts
    </body>
</html>
