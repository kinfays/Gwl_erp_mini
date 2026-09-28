@php($canDeleteAccount = $canDeleteAccount ?? true)

@if (! $canDeleteAccount)
    <section class="profile-section" aria-labelledby="profile-delete-title">
        <header class="profile-section-head">
            <h2 id="profile-delete-title">{{ __('Account Deletion') }}</h2>
            <p>{{ __('Employee accounts are managed by UAC and cannot be self-deleted.') }}</p>
        </header>
    </section>
@else
    <section class="profile-section" aria-labelledby="profile-delete-title">
        <header class="profile-section-head">
            <h2 id="profile-delete-title">{{ __('Delete Account') }}</h2>
            <p>{{ __('Once your account is deleted, all of its resources and data will be permanently deleted.') }}</p>
        </header>

        <div>
            <button
                type="button"
                class="btn btn-danger"
                x-data=""
                x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
            >{{ __('Delete Account') }}</button>
        </div>

        <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" max-width="md" focusable>
            <form method="post" action="{{ route('profile.destroy') }}" class="breeze-modal-form">
                @csrf
                @method('delete')

                <h2 class="ui-modal-title" id="confirm-user-deletion-title">
                    {{ __('Are you sure you want to delete your account?') }}
                </h2>

                <p class="ui-modal-desc">
                    {{ __('Please enter your password to confirm account deletion.') }}
                </p>

                <div class="ui-field">
                    <x-input-label for="password" value="{{ __('Password') }}" class="sr-only" />

                    <x-text-input
                        id="password"
                        name="password"
                        type="password"
                        placeholder="{{ __('Password') }}"
                    />

                    <x-input-error :messages="$errors->userDeletion->get('password')" />
                </div>

                <div class="ui-form-actions">
                    <x-secondary-button x-on:click="$dispatch('close')">
                        {{ __('Cancel') }}
                    </x-secondary-button>

                    <x-danger-button>
                        {{ __('Delete Account') }}
                    </x-danger-button>
                </div>
            </form>
        </x-modal>
    </section>
@endif
