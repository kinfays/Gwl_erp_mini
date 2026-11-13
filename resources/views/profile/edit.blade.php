<x-erp-layout module="profile" title="Profile">
    <div class="content profile-page">
        <x-ui.page-header :title="__('Profile')" :description="__('Review your employee details and manage your account access.')" />

        @if ($mustChangePassword)
            <x-ui.alert tone="warning" role="alert">
                {{ __('You are using the default password. Change it before continuing to the portal.') }}
            </x-ui.alert>
        @endif

        <div class="profile-layout">
            <div class="profile-actions">
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

            <x-ui.card :title="__('My ICT Assets')" :description="__('To report a problem or request a change, contact the ICT team.')" :padded="false" class="profile-assets">
                @if (! $employee)
                    <x-ui.empty-state icon="user-x" :title="__('No employee record is linked to this account.')" />
                @elseif ($myAssets->isEmpty())
                    <x-ui.empty-state icon="laptop" :title="__('No ICT assets are assigned to you.')" />
                @else
                    <x-ui.table :label="__('ICT assets assigned to you')" :sticky="false">
                        <x-slot:head>
                            <tr>
                                <th>{{ __('Device') }}</th>
                                <th>{{ __('Type / Model') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Warranty expiry') }}</th>
                            </tr>
                        </x-slot:head>

                        @foreach ($myAssets as $asset)
                            <tr>
                                <td>
                                    <span class="ui-cell-stack">
                                        <span class="ui-person-name">{{ $asset->asset_name }}</span>
                                        <span class="ui-person-sub mono">{{ $asset->serial_number ?: __('No serial') }}</span>
                                    </span>
                                </td>
                                <td>
                                    <span class="ui-cell-stack">
                                        <span>{{ \App\Models\IctAsset::ASSET_TYPES[$asset->device_category][$asset->asset_type] ?? $asset->asset_type }}</span>
                                        <span class="ui-person-sub">{{ $asset->assetModel?->name ?: __('No model') }}</span>
                                    </span>
                                </td>
                                <td><x-ui.status-pill domain="asset" :status="$asset->status" /></td>
                                <td class="nowrap {{ $asset->warranty_expires_at ? '' : 'cell-muted' }}">{{ $asset->warranty_expires_at?->format('d M Y') ?: __('Unknown') }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
            </div>

            <div class="profile-actions">
                <x-ui.card>
                    @include('profile.partials.update-profile-information-form')
                </x-ui.card>

                <x-ui.card>
                    @include('profile.partials.update-password-form')
                </x-ui.card>
            </div>
        </div>
    </div>
</x-erp-layout>
