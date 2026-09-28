<div>
    @php
        $viewer = auth()->user();
        $canCreate = $viewer->hasRoles('super_admin') || $viewer->hasPermission('assets.create');
        $canEdit = $viewer->hasRoles('super_admin') || $viewer->hasPermission('assets.edit');
    @endphp

    <x-ui.page-header title="Network" description="Routers, switches, access points, MiFi and P2P radios.">
        @if ($canCreate)
            <x-slot:actions>
                <button type="button" wire:click="openCreate" class="btn btn-primary">
                    <x-ui.icon name="plus" />
                    Add Network Device
                </button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.card :padded="false">
        <div class="ui-toolbar" role="search" aria-label="Filter network devices">
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input type="text" wire:model.live="search" class="form-input ui-input has-icon" placeholder="Search name, serial, IP, SSID" aria-label="Search network devices">
            </div>
            <select wire:model.live="status" class="form-input" aria-label="Status">
                <option value="">All statuses</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
            <select wire:model.live="assetType" class="form-input" aria-label="Device type">
                <option value="">All types</option>
                @foreach ($assetTypes as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="perPage" class="form-input" aria-label="Rows per page">
                <option value="15">15 per page</option>
                <option value="30">30 per page</option>
                <option value="50">50 per page</option>
            </select>
        </div>

        <div class="ui-loading-host">
            <x-ui.table label="Network devices" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Device</th>
                        <th>Type / Model</th>
                        <th>Network</th>
                        <th>Credentials</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Action</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($assets as $asset)
                    @php($revealed = $canViewSecrets && $revealedAssetId === $asset->id)
                    <tr wire:key="network-{{ $asset->id }}">
                        <td>
                            <span class="ui-cell-stack">
                                <span class="ui-person-name">{{ $asset->asset_name }}</span>
                                <span class="ui-person-sub mono">{{ $asset->serial_number ?: 'No serial' }}</span>
                            </span>
                        </td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ $assetTypes[$asset->asset_type] ?? $asset->asset_type }}</span>
                                <span class="ui-person-sub">{{ $asset->assetModel?->name ?: 'No model' }}</span>
                            </span>
                        </td>
                        <td>
                            <span class="ui-cell-stack">
                                <span class="mono">{{ $asset->device_ip ?: 'No IP' }}</span>
                                <span class="ui-person-sub">SSID: {{ $asset->ssid ?: 'n/a' }}</span>
                            </span>
                        </td>
                        <td>
                            @if ($canViewSecrets)
                                <div class="secret-cell">
                                    <span class="ui-cell-stack mono">
                                        <span>Login: {{ $revealed ? ($asset->login_password ?: 'n/a') : '••••••••' }}</span>
                                        <span>SSID: {{ $revealed ? ($asset->ssid_password ?: 'n/a') : '••••••••' }}</span>
                                    </span>
                                    <button type="button" wire:click="toggleReveal({{ $asset->id }})" class="btn btn-ghost btn-sm btn-icon" aria-pressed="{{ $revealed ? 'true' : 'false' }}" title="{{ $revealed ? 'Hide' : 'Reveal' }}" aria-label="{{ $revealed ? 'Hide' : 'Reveal' }} credentials for {{ $asset->asset_name }}">
                                        <x-ui.icon :name="$revealed ? 'eye-off' : 'eye'" />
                                    </button>
                                </div>
                            @else
                                <span class="ui-hint">Restricted</span>
                            @endif
                        </td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ $asset->district?->district_name ?: 'No district' }}</span>
                                <span class="ui-person-sub">{{ $asset->actual_location ?: 'No location note' }}</span>
                            </span>
                        </td>
                        <td><x-ui.status-pill domain="asset" :status="$asset->status" /></td>
                        <td class="actions">
                            @if ($canEdit)
                                <button type="button" wire:click="openEdit({{ $asset->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $asset->asset_name }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                            @else
                                <span class="ui-hint">Read only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="7" icon="network" title="No network devices found for your filters." description="Try a different search or clear the filters." />
                @endforelse

                <x-slot:footer>
                    <p class="pager-summary">
                        Showing {{ $assets->firstItem() ?? 0 }} - {{ $assets->lastItem() ?? 0 }} of {{ $assets->total() }} devices
                    </p>
                    <div>{{ $assets->links() }}</div>
                </x-slot:footer>
            </x-ui.table>

            <div wire:loading.delay class="table-skeleton">
                <span class="skeleton-line"></span>
                <span class="skeleton-line"></span>
                <span class="skeleton-line short"></span>
            </div>
        </div>
    </x-ui.card>

    @if ($showForm)
        <x-ui.modal :title="$editingAssetId ? 'Edit Network Device' : 'Add Network Device'" close="closeForm()" size="lg" icon="network">
            <div class="ui-form-grid">
                <x-ui.select label="Device Type" wire:model.defer="form.asset_type">
                    <option value="">Select type</option>
                    @foreach ($assetTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-assets.model-select :models="$models" />

                <x-ui.input label="Asset Name" wire:model.defer="form.asset_name" />
                <x-ui.input label="UserName" wire:model.defer="form.device_username" autocomplete="off" />

                <x-ui.input label="Login Password" type="password" wire:model.defer="form.login_password" :placeholder="$editingAssetId ? 'Leave blank to keep current' : ''" autocomplete="new-password" />
                <x-ui.input label="SSID" wire:model.defer="form.ssid" />

                <x-ui.input label="SSID Password" type="password" wire:model.defer="form.ssid_password" :placeholder="$editingAssetId ? 'Leave blank to keep current' : ''" autocomplete="new-password" />
                <x-ui.input label="Management IP" wire:model.defer="form.device_ip" class="mono" inputmode="decimal" />

                <x-ui.select label="District" wire:model.defer="form.district_id">
                    <option value="">Select district</option>
                    @foreach ($formDistricts as $district)
                        <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Serial Number" wire:model.defer="form.serial_number" class="mono" />

                <x-ui.select label="Status" wire:model.defer="form.status">
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Actual Location" wire:model.defer="form.actual_location" placeholder="e.g. Server Room, 2nd Floor" />

                <x-assets.actor-region :region="$actorRegion" label="NetRegion" />
            </div>

            <x-slot:footer>
                <button type="button" wire:click="closeForm" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="save" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingAssetId ? 'Update Device' : 'Create Device' }}
                </button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
