<section class="profile-section" aria-labelledby="profile-password-title">
    <header class="profile-section-head">
        <h2 id="profile-password-title">{{ __('Update Password') }}</h2>
        <p id="profile-password-rules">{{ __('Use at least 5 characters with at least one letter and one number.') }}</p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="profile-form">
        @csrf
        @method('put')

        @foreach ([
            'current_password' => ['update_password_current_password', __('Current Password'), 'current-password'],
            'password' => ['update_password_password', __('New Password'), 'new-password'],
            'password_confirmation' => ['update_password_password_confirmation', __('Confirm Password'), 'new-password'],
        ] as $field => [$fieldId, $fieldLabel, $autocomplete])
            @php($fieldErrors = $errors->updatePassword->get($field))
            <div class="ui-field">
                <label class="ui-label" for="{{ $fieldId }}">{{ $fieldLabel }}</label>
                <input
                    id="{{ $fieldId }}"
                    name="{{ $field }}"
                    type="password"
                    @class(['form-input', 'ui-input', 'is-invalid' => $fieldErrors])
                    autocomplete="{{ $autocomplete }}"
                    @if ($field === 'password') aria-describedby="profile-password-rules{{ $fieldErrors ? ' '.$fieldId.'-error' : '' }}" @elseif ($fieldErrors) aria-describedby="{{ $fieldId }}-error" @endif
                    @if ($fieldErrors) aria-invalid="true" @endif
                >
                @foreach ($fieldErrors as $message)
                    <p class="ui-error" @if ($loop->first) id="{{ $fieldId }}-error" @endif>
                        <x-ui.icon name="circle-alert" class="icon-sm" />
                        <span>{{ $message }}</span>
                    </p>
                @endforeach
            </div>
        @endforeach

        <div class="profile-form-actions">
            <button type="submit" class="btn btn-primary">{{ __('Save Password') }}</button>

            @if (session('status') === 'password-updated')
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
