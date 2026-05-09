<x-erp-layout module="profile" title="Profile">
    <div class="content profile-page">
        <div class="page-head" style="padding-left:0;padding-right:0;background:transparent;border:0">
            <div class="ph-left">
                <h2>{{ __('Profile') }}</h2>
                <p>{{ __('Review your employee details and manage your account access.') }}</p>
            </div>
        </div>

        @if ($mustChangePassword)
            <div class="erp-card profile-alert profile-alert-warning">
                {{ __('You are using the default password. Change it before continuing to the portal.') }}
            </div>
        @endif

        <div class="profile-layout">
            <section class="erp-card profile-details">
                <div class="profile-card-head">
                    <div>
                        <h3>{{ __('Employee Details') }}</h3>
                        <p>{{ __('These details are maintained by HR and are read-only here.') }}</p>
                    </div>
                </div>

                @if ($employee)
                    <div class="profile-facts">
                        @foreach ($employeeDetails as $label => $value)
                            <div class="profile-fact">
                                <span>{{ $label }}</span>
                                <strong>{{ filled($value) ? $value : __('Not set') }}</strong>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="empty-state">
                        {{ __('No employee record is linked to this account.') }}
                    </div>
                @endif
            </section>

            <div class="profile-actions">
                <div class="erp-card">
                    @include('profile.partials.update-profile-information-form')
                </div>

                <div class="erp-card">
                    @include('profile.partials.update-password-form')
                </div>

                <div class="erp-card">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-erp-layout>
