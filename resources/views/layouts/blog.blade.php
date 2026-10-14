<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light dark">
        {{-- Inside the portal's login: nothing here is for search engines. --}}
        <meta name="robots" content="noindex, nofollow">
        @if (filled($description))
            <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($description), 200) }}">
        @endif

        <title>{{ $title }} · {{ ($region ? $region.' ' : '').__('Regional Blog') }}</title>
        @include('partials.favicon')
        @include('partials.theme-script')

        @vite(['resources/css/blog.css', 'resources/js/app.js', 'resources/js/blog.js'])
        @livewireStyles
        @stack('head')
    </head>
    <body class="blog-body">
        <a href="#main-content" class="b-skip">{{ __('Skip to the articles') }}</a>

        <header class="b-mast">
            <div class="b-util">
                <div class="b-wrap {{ $wide ? 'b-wrap-wide' : '' }}">
                    <div class="b-util-left">
                        <a href="{{ route('dashboard') }}">
                            <x-ui.icon name="arrow-left" />
                            {{ __('Back to ERP') }}
                        </a>
                        <span class="b-date">{{ now()->format('l, j F Y') }}</span>
                    </div>

                    <div class="b-util-right">
                        @if ($canManage)
                            <a href="{{ route('blog.manage') }}" @if (request()->routeIs('blog.manage', 'blog.create', 'blog.edit')) aria-current="page" @endif>
                                <x-ui.icon name="pencil" />
                                {{ __('Manage') }}
                            </a>
                        @endif

                        <button type="button" x-data x-on:click="$store.theme.toggle()" aria-label="{{ __('Switch theme') }}">
                            <x-ui.icon name="moon" x-show="! $store.theme.dark" />
                            <x-ui.icon name="sun" x-show="$store.theme.dark" x-cloak />
                            <span class="b-sr">{{ __('Switch theme') }}</span>
                        </button>

                        @if ($user)
                            <div class="b-user" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false">
                                <button type="button" x-on:click="open = ! open" aria-haspopup="menu" x-bind:aria-expanded="open.toString()" aria-controls="blog-user-menu">
                                    <x-ui.icon name="circle-user" />
                                    <span>{{ \Illuminate\Support\Str::words($user->full_name ?? $user->email, 2, '') }}</span>
                                    <x-ui.icon name="chevron-down" />
                                </button>
                                <div id="blog-user-menu" class="b-user-panel" role="menu" x-show="open" x-cloak>
                                    <p><strong>{{ $user->full_name ?? $user->email }}</strong>{{ $region ?? __('All regions') }}</p>
                                    <a href="{{ route('dashboard') }}" role="menuitem">{{ __('Back to ERP') }}</a>
                                    <a href="{{ route('profile.edit') }}" role="menuitem">{{ __('My profile') }}</a>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" role="menuitem">{{ __('Sign out') }}</button>
                                    </form>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="b-wrap {{ $wide ? 'b-wrap-wide' : '' }}">
                <div class="b-brandrow">
                    <a href="{{ route('blog.home') }}" class="b-wordmark" aria-label="{{ ($region ? $region.' ' : '').__('Regional Blog') }}: {{ __('home') }}">
                        <img src="{{ asset('images/gwlnew.png') }}" alt="" width="48" height="48" decoding="async">
                        <span class="b-wordmark-text">
                            <small>{{ __('Ghana Water Limited') }}</small>
                            <strong>{{ $region ?? __('All regions') }} <em>{{ __('Regional Blog') }}</em></strong>
                        </span>
                    </a>

                    <form class="b-search" action="{{ route('blog.search') }}" method="GET" role="search">
                        <label class="b-sr" for="blog-search">{{ __('Search the blog') }}</label>
                        <input id="blog-search" type="search" name="q" value="{{ request()->routeIs('blog.search') ? request('q') : '' }}" placeholder="{{ __('Search articles') }}" maxlength="80" autocomplete="off">
                        @if (request()->filled('region'))
                            <input type="hidden" name="region" value="{{ (int) request('region') }}">
                        @endif
                        <button type="submit">
                            <x-ui.icon name="search" />
                            <span class="b-sr">{{ __('Search') }}</span>
                        </button>
                    </form>
                </div>
            </div>

            <nav class="b-cats" aria-label="{{ __('Sections') }}">
                <div class="b-wrap {{ $wide ? 'b-wrap-wide' : '' }}">
                    <ul>
                        <li><a href="{{ route('blog.home') }}" @if ($active === 'home') aria-current="page" @endif>{{ __('All') }}</a></li>
                        @foreach ($categories as $slug => $label)
                            <li><a href="{{ route('blog.category', $slug) }}" @if ($active === 'category:'.$slug) aria-current="page" @endif>{{ $label }}</a></li>
                        @endforeach
                        <li><a href="{{ route('blog.gallery') }}" @if ($active === 'gallery') aria-current="page" @endif>{{ __('Photo Gallery') }}</a></li>
                    </ul>
                </div>
            </nav>
        </header>

        <main id="main-content" tabindex="-1">
            {{ $slot }}
        </main>

        <footer class="b-foot">
            <div class="b-wrap {{ $wide ? 'b-wrap-wide' : '' }}">
                <span>© {{ now()->year }} {{ __('Ghana Water Limited') }}. {{ __('For staff only: please do not share outside the company.') }}</span>
                <a href="{{ route('dashboard') }}">{{ __('Back to the ERP portal') }}</a>
            </div>
        </footer>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
