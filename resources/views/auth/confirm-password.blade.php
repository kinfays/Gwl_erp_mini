<x-guest-layout>
    <div class="auth-intro">
        <h2>{{ __('Confirm your password') }}</h2>
        <p>{{ __('This is a secure area of the application. Please confirm your password before continuing.') }}</p>
    </div>

    <form method="POST" action="{{ route('password.confirm') }}" class="auth-form">
        @csrf

        <!-- Password -->
        <div class="ui-field">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" />
        </div>

        <x-primary-button class="btn-block">
            {{ __('Confirm') }}
        </x-primary-button>
    </form>
</x-guest-layout>
