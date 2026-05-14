<!--Bismillah
    Developed by
    FaisalEwuntomah (Faysysgh)
    GWL -->

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
    <title>{{ config('app.name', 'Laravel') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.favicon')

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <script>
        (() => {
            const preference = localStorage.getItem('gwl-theme');
            const dark = preference
                ? preference === 'dark'
                : window.matchMedia('(prefers-color-scheme: dark)').matches;

            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        html, body {
            height: 100%;
            margin: 0;
        }

        /* Background video */
        .video-bg {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: -1;
        }

        .dark .video-bg {
            filter: brightness(0.45) saturate(0.85);
        }

        /* Centering container */
        .guest-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            color: #172234;
            transition: background-color 160ms ease, color 160ms ease;
        }

        .dark .guest-wrapper {
            background: rgba(2, 6, 23, 0.34);
            color: #e5edf7;
        }

        /* Login card */
        .auth-card {
            width: 100%;
            max-width: 420px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 2.5rem 2rem;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            color: inherit;
            transition: background-color 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
        }

        .dark .auth-card {
            background: rgba(15, 23, 42, 0.94);
            border: 1px solid rgba(148, 163, 184, 0.24);
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.48);
        }

        .auth-brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .auth-brand img {
            display: block;
            max-height: 80px;
            margin: 0 auto 0.75rem;
        }

        .auth-brand h1 {
            font-size: 1.25rem;
            font-weight: 600;
            color: inherit;
        }

        .auth-card-header {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 1rem;
        }

        .theme-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 9999px;
            border: 1px solid rgba(23, 34, 52, 0.2);
            background: rgba(255, 255, 255, 0.9);
            color: #172234;
            transition: background-color 160ms ease, border-color 160ms ease, color 160ms ease;
        }

        .theme-toggle:hover {
            border-color: rgba(23, 34, 52, 0.35);
            background: #ffffff;
        }

        .theme-toggle:focus-visible {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
        }

        .theme-toggle svg {
            width: 1rem;
            height: 1rem;
        }

        .dark .theme-toggle {
            background: rgba(15, 23, 42, 0.92);
            border-color: rgba(148, 163, 184, 0.4);
            color: #e2e8f0;
        }

        .dark .theme-toggle:hover {
            border-color: rgba(148, 163, 184, 0.7);
            background: rgba(30, 41, 59, 0.95);
        }

        .auth-card input[type=email],
        .auth-card input[type=password],
        .auth-card input[type=text] {
            background: #ffffff;
            color: #111827;
        }

        .dark .auth-card input[type=email],
        .dark .auth-card input[type=password],
        .dark .auth-card input[type=text] {
            background: rgba(15, 23, 42, 0.92);
            border-color: #475569;
            color: #e5edf7;
        }

        .dark .auth-card input[type=email]::placeholder,
        .dark .auth-card input[type=password]::placeholder,
        .dark .auth-card input[type=text]::placeholder {
            color: #94a3b8;
        }

        .dark .auth-card input[type=checkbox] {
            background-color: #0f172a;
            border-color: #64748b;
        }

        .dark .auth-card label,
        .dark .auth-card .text-gray-600,
        .dark .auth-card .text-gray-700 {
            color: #cbd5e1 !important;
        }

        .dark .auth-card a.text-gray-600:hover {
            color: #f8fafc !important;
        }

        .dark .auth-card .bg-red-50 {
            background: rgba(127, 29, 29, 0.34) !important;
        }

        .dark .auth-card .border-red-200 {
            border-color: rgba(248, 113, 113, 0.38) !important;
        }

        .dark .auth-card .text-red-700,
        .dark .auth-card .text-red-600 {
            color: #fca5a5 !important;
        }

        .dark .auth-card .text-green-600 {
            color: #86efac !important;
        }
    </style>
</head>

<body>

    {{-- Background Video --}}
    <video class="video-bg" autoplay muted loop playsinline>
        <source src="{{ asset('images/bgwater.mp4') }}" type="video/mp4">
    </video>

    {{-- Centered Content --}}
    <div class="guest-wrapper">
        <div class="auth-card">
            <div class="auth-card-header">
                <button
                    type="button"
                    class="theme-toggle"
                    x-on:click="toggleTheme()"
                    x-bind:aria-label="darkMode ? '{{ __('Switch to light mode') }}' : '{{ __('Switch to dark mode') }}'"
                >
                    <svg x-show="!darkMode" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="12" cy="12" r="4" />
                        <path stroke-linecap="round" d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                    </svg>
                    <svg x-show="darkMode" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A8.5 8.5 0 1 1 11.2 3a6.5 6.5 0 0 0 9.8 9.8Z" />
                    </svg>
                </button>
            </div>

            {{-- Branding --}}
            <div class="auth-brand">
                <img src="{{ asset('images/gwlnew.png') }}" alt="Logo">
                <h1>{{ __('GWL Staff Portal') }}</h1>
            </div>

            {{-- Slot renders login.blade.php unchanged --}}
            {{ $slot }}

        </div>
    </div>

</body>
</html>
