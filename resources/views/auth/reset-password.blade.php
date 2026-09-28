<x-guest-layout>
    @php
        $isSetPassword = $isSetPassword ?? false;
        $emailValue = old('email', $request->email);
        $staffIdValue = old('staff_id', $staffId ?? $request->query('staff_id'));
    @endphp

    <div class="auth-intro">
        <h2>{{ $isSetPassword ? __('Set your password') : __('Choose a new password') }}</h2>
        <p>{{ $isSetPassword ? __('Create the password you will use to sign in to the portal.') : __('Enter a new password for your portal account.') }}</p>
    </div>

    <form method="POST" action="{{ route('password.store') }}" class="auth-form">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <input type="hidden" name="email" value="{{ $emailValue }}">

        <!-- Staff ID -->
        <div class="ui-field">
            <x-input-label for="staff_id" :value="__('Staff ID')" />
            <x-text-input id="staff_id" type="text" name="staff_id" :value="$staffIdValue" readonly autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <!-- Password -->
        <div class="ui-field">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" type="password" name="password" required autofocus autocomplete="new-password" aria-describedby="password-rules" />
            <p class="ui-hint" id="password-rules">{{ __('At least 5 characters, including one letter and one number.') }}</p>
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <!-- Confirm Password -->
        <div class="ui-field">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input id="password_confirmation"
                                type="password"
                                name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" />
        </div>

        <x-primary-button class="btn-block">
            {{ $isSetPassword ? __('Set Password') : __('Reset Password') }}
        </x-primary-button>
    </form>
</x-guest-layout>
