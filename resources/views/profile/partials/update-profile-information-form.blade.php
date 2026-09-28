<section class="profile-section" aria-labelledby="profile-email-title">
    <header class="profile-section-head">
        <h2 id="profile-email-title">{{ __('Account Email') }}</h2>
        <p>{{ __('This email is used for account notifications and employee contact records.') }}</p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="profile-form">
        @csrf
        @method('patch')

        <div class="ui-field">
            <label class="ui-label" for="email">{{ __('Email') }}</label>
            <input
                id="email"
                name="email"
                type="email"
                @class(['form-input', 'ui-input', 'is-invalid' => $errors->has('email')])
                value="{{ old('email', $user->email) }}"
                required
                autocomplete="username"
                @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
            >
            @error('email')
                <p class="ui-error" id="email-error">
                    <x-ui.icon name="circle-alert" class="icon-sm" />
                    <span>{{ $message }}</span>
                </p>
            @enderror

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div class="profile-inline-note">
                    {{ __('Your email address is unverified.') }}

                    <button form="send-verification" class="profile-link-button">
                        {{ __('Re-send verification email') }}
                    </button>
                </div>

                @if (session('status') === 'verification-link-sent')
                    <p class="profile-saved" role="status">{{ __('A new verification link has been sent.') }}</p>
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
                    role="status"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
