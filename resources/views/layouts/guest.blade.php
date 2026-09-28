<!--Bismillah
    Developed by
    FaisalEwuntomah (Faysysgh)
    GWL -->

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name', 'Laravel') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.favicon')

    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-script')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="auth-body">
    <div
        class="auth-scene"
        x-data="{
            paused: true,
            init() {
                // Moving background: never auto-play for people who ask for less motion, and
                // remember an explicit pause (WCAG 2.2.2).
                let stored = null;
                try { stored = localStorage.getItem('gwl-bg-video'); } catch (error) {}
                const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                this.paused = stored ? stored === 'paused' : reduce;
                this.$nextTick(() => this.sync());
            },
            sync() {
                const video = this.$refs.video;
                if (! video) return;
                if (this.paused) {
                    video.pause();
                } else {
                    video.play().catch(() => { this.paused = true; });
                }
            },
            toggle() {
                this.paused = ! this.paused;
                try { localStorage.setItem('gwl-bg-video', this.paused ? 'paused' : 'playing'); } catch (error) {}
                this.sync();
            },
        }"
    >
        <video x-ref="video" class="auth-video" muted loop playsinline preload="metadata" aria-hidden="true" tabindex="-1">
            <source src="{{ asset('images/bgwater.mp4') }}" type="video/mp4">
        </video>

        <main class="auth-wrapper">
            <div class="auth-card">
                <div class="auth-card-header">
                    <button
                        type="button"
                        class="icon-btn"
                        x-on:click="$store.theme.toggle()"
                        x-bind:aria-label="$store.theme.dark ? '{{ __('Switch to light mode') }}' : '{{ __('Switch to dark mode') }}'"
                        aria-label="{{ __('Switch theme') }}"
                    >
                        <x-ui.icon name="sun" x-show="$store.theme.dark" x-cloak />
                        <x-ui.icon name="moon" x-show="! $store.theme.dark" />
                    </button>
                </div>

                {{-- Branding --}}
                <div class="auth-brand">
                    <img src="{{ asset('images/gwlnew.png') }}" alt="{{ __('GWL logo') }}">
                    <h1>{{ __('GWL Staff Portal') }}</h1>
                </div>

                {{ $slot }}
            </div>
        </main>

        <button type="button" class="auth-video-toggle" x-on:click="toggle()">
            <x-ui.icon name="pause" class="icon-sm" x-show="! paused" x-cloak />
            <x-ui.icon name="play" class="icon-sm" x-show="paused" />
            <span x-text="paused ? '{{ __('Play background') }}' : '{{ __('Pause background') }}'">{{ __('Play background') }}</span>
        </button>
    </div>
</body>
</html>
