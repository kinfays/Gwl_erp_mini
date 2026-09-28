<x-guest-layout>
    <div class="auth-intro">
        <h2>{{ __('Forgot your password?') }}</h2>
        <p>{{ __('No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="auth-form">
        @csrf

        <!-- Email Address -->
        <div class="ui-field">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus autocomplete="email" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <x-primary-button class="btn-block">
            <x-ui.icon name="send" />
            {{ __('Email Password Reset Link') }}
        </x-primary-button>

        <p class="auth-footnote">
            <a class="text-link" href="{{ route('login') }}">
                <x-ui.icon name="arrow-left" class="icon-sm" />
                {{ __('Back to sign in') }}
            </a>
        </p>
    </form>
</x-guest-layout>
