<section class="profile-section">
    <header class="profile-section-head">
        <h3>{{ __('Account Email') }}</h3>
        <p>{{ __('This email is used for account notifications and employee contact records.') }}</p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="profile-form">
        @csrf
        @method('patch')

        <div class="form-field">
            <label class="form-label" for="email">{{ __('Email') }}</label>
            <input
                id="email"
                name="email"
                type="email"
                class="form-input"
                value="{{ old('email', $user->email) }}"
                required
                autocomplete="username"
            >
            @error('email')
                <span class="form-error">{{ $message }}</span>
            @enderror

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div class="profile-inline-note">
                    {{ __('Your email address is unverified.') }}

                    <button form="send-verification" class="profile-link-button">
                        {{ __('Re-send verification email') }}
                    </button>
                </div>

                @if (session('status') === 'verification-link-sent')
                    <p class="profile-saved">{{ __('A new verification link has been sent.') }}</p>
                @endif
            @endif
        </div>

        <div class="profile-form-actions">
            <button type="submit" class="btn btn-primary">{{ __('Save Email') }}</button>

            @if (session('status') === 'profile-updated')
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
