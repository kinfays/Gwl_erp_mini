<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name', 'Laravel') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta name="csrf-token" content="{{ csrf_token() }}">

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

        /* Centering container */
        .guest-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        /* Login card */
        .auth-card {
            width: 100%;
            max-width: 420px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 2.5rem 2rem;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
        }

        .auth-brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .auth-brand img {
            max-height: 80px;
            margin-bottom: 0.75rem;
        }

        .auth-brand h1 {
            font-size: 1.25rem;
            font-weight: 600;
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

            {{-- Branding --}}
            <div class="auth-brand">
                <img src="{{ asset('images/gwlnew.png') }}" alt="Logo">
                <h1>{{ config('app.name', 'System Name') }}</h1>
            </div>

            {{-- Slot renders login.blade.php unchanged --}}
            {{ $slot }}

        </div>
    </div>

</body>
</html>