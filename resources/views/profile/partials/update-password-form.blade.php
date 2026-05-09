<section class="profile-section">
    <header class="profile-section-head">
        <h3>{{ __('Update Password') }}</h3>
        <p>{{ __('Use at least 5 characters with at least one letter and one number.') }}</p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="profile-form">
        @csrf
        @method('put')

        <div class="form-field">
            <label class="form-label" for="update_password_current_password">{{ __('Current Password') }}</label>
            <input
                id="update_password_current_password"
                name="current_password"
                type="password"
                class="form-input"
                autocomplete="current-password"
            >
            @foreach ($errors->updatePassword->get('current_password') as $message)
                <span class="form-error">{{ $message }}</span>
            @endforeach
        </div>

        <div class="form-field">
            <label class="form-label" for="update_password_password">{{ __('New Password') }}</label>
            <input
                id="update_password_password"
                name="password"
                type="password"
                class="form-input"
                autocomplete="new-password"
            >
            @foreach ($errors->updatePassword->get('password') as $message)
                <span class="form-error">{{ $message }}</span>
            @endforeach
        </div>

        <div class="form-field">
            <label class="form-label" for="update_password_password_confirmation">{{ __('Confirm Password') }}</label>
            <input
                id="update_password_password_confirmation"
                name="password_confirmation"
                type="password"
                class="form-input"
                autocomplete="new-password"
            >
            @foreach ($errors->updatePassword->get('password_confirmation') as $message)
                <span class="form-error">{{ $message }}</span>
            @endforeach
        </div>

        <div class="profile-form-actions">
            <button type="submit" class="btn btn-primary">{{ __('Save Password') }}</button>

            @if (session('status') === 'password-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="profile-saved"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
