<x-guest-layout>
    <div class="auth-intro">
        <h2>{{ __('Sign in') }}</h2>
        <p>{{ __('Use your staff ID and portal password.') }}</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="auth-form" x-data="{ clearErrors() { this.$root.querySelectorAll('[data-login-error]').forEach((el) => el.remove()) } }">
        @csrf

        @if ($errors->has('staff_id'))
            <div data-login-error class="alert alert-danger" role="alert">
                <x-ui.icon name="circle-alert" class="alert-icon" />
                <div class="alert-body">{{ $errors->first('staff_id') }}</div>
            </div>
        @endif

        <!-- Staff ID -->
        <div class="ui-field">
            <x-input-label for="staff_id" :value="__('Staff ID')" />
            <input id="staff_id"
                   type="text"
                   name="staff_id"
                   value="{{ old('staff_id') }}"
                   required
                   autofocus
                   autocomplete="username"
                   x-on:input="clearErrors()"
                   class="form-input ui-input">
        </div>

        <!-- Password -->
        <div class="ui-field">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password"
                            type="password"
                            name="password"
                            x-on:input="clearErrors()"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" />
        </div>

        <div class="auth-row">
            <!-- Remember Me -->
            <label for="remember_me" class="auth-check">
                <input id="remember_me" type="checkbox" name="remember">
                <span>{{ __('Remember me') }}</span>
            </label>

            @if (Route::has('password.request'))
                <a class="text-link" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif
        </div>

        <x-primary-button class="btn-block">
            <x-ui.icon name="log-in" />
            {{ __('Log in') }}
        </x-primary-button>
    </form>
</x-guest-layout>
