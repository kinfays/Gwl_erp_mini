<x-erp-layout module="profile" title="Profile">
    <div class="content profile-page">
        <x-ui.page-header :title="__('Profile')" :description="__('Review your employee details and manage your account access.')" />

        @if ($mustChangePassword)
            <x-ui.alert tone="warning" role="alert">
                {{ __('You are using the default password. Change it before continuing to the portal.') }}
            </x-ui.alert>
        @endif

        <div class="profile-layout">
            <x-ui.card :title="__('Employee Details')" :description="__('These details are maintained by HR and are read-only here.')" class="profile-details">
                @if ($employee)
                    <dl class="profile-facts">
                        @foreach ($employeeDetails as $label => $value)
                            <div class="profile-fact">
                                <dt>{{ $label }}</dt>
                                <dd @class(['is-empty' => ! filled($value)])>{{ filled($value) ? $value : __('Not set') }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @else
                    <x-ui.empty-state icon="user-x" :title="__('No employee record is linked to this account.')" />
                @endif
            </x-ui.card>

            <div class="profile-actions">
                <x-ui.card>
                    @include('profile.partials.update-profile-information-form')
                </x-ui.card>

                <x-ui.card>
                    @include('profile.partials.update-password-form')
                </x-ui.card>

                <x-ui.card>
                    @include('profile.partials.delete-user-form')
                </x-ui.card>
            </div>
        </div>
    </div>
</x-erp-layout>
