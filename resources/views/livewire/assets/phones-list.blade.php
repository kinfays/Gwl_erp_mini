<div>
    @php
        $viewer = auth()->user();
        $canCreate = $viewer->hasRoles('super_admin') || $viewer->hasPermission('assets.create');
        $canEdit = $viewer->hasRoles('super_admin') || $viewer->hasPermission('assets.edit');
    @endphp

    <x-ui.page-header title="Phones" description="POS terminals, SIM cards and mobile phones.">
        @if ($canCreate)
            <x-slot:actions>
                <button type="button" wire:click="openCreate" class="btn btn-primary">
                    <x-ui.icon name="plus" />
                    Add Phone Device
                </button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.card :padded="false">
        <div class="ui-toolbar" role="search" aria-label="Filter phone devices">
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input type="text" wire:model.live="search" class="form-input ui-input has-icon" placeholder="Search name, serial, IMEI, number" aria-label="Search phone devices">
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
            <x-ui.table label="Phone devices" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Device</th>
                        <th>Type / Model</th>
                        <th>Numbers</th>
                        <th>Assigned To</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Action</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($assets as $asset)
                    <tr wire:key="phone-{{ $asset->id }}">
                        <td>
                            <span class="ui-cell-stack">
                                <span class="ui-person-name">{{ $asset->asset_name }}</span>
                                <span class="ui-person-sub mono">
                                    {{ $asset->serial_number ?: 'No serial' }}
                                    @if ($asset->imei)
                                        · IMEI {{ $asset->imei }}
                                    @endif
                                </span>
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
                                <span class="mono">{{ $asset->device_phone_number ?: 'No device number' }}</span>
                                <span class="ui-person-sub">User: {{ $asset->user_phone_number ?: 'n/a' }}</span>
                            </span>
                        </td>
                        <td @class(['nowrap', 'cell-muted' => ! $asset->assignedTo])>{{ $asset->assignedTo?->full_name ?: 'Unassigned' }}</td>
                        <td>
                            <span class="ui-cell-stack">
                                <x-ui.status-pill domain="asset" :status="$asset->status" />
                                @if ($mdmLinks && $asset->mdmDevice && ! $asset->mdmDevice->isDeleted())
                                    <a href="{{ route('assets.mdm.devices.show', $asset->mdmDevice->id) }}" class="ui-person-sub" title="Open the MDM record">
                                        MDM · {{ $asset->mdmDevice->is_lost ? 'Lost mode' : ($asset->mdmDevice->needs_review ? 'Needs review' : ($asset->mdmDevice->policy_compliant === false ? 'Non-compliant' : 'Managed')) }}
                                    </a>
                                @endif
                            </span>
                        </td>
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
                    <x-ui.empty-row :colspan="6" icon="smartphone" title="No phone devices found for your filters." description="Try a different search or clear the filters." />
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
        <x-ui.modal :title="$editingAssetId ? 'Edit Phone Device' : 'Add Phone Device'" close="closeForm()" size="lg" icon="smartphone">
            <div class="ui-form-grid">
                <x-ui.input label="Asset Name" wire:model.defer="form.asset_name" />
                <x-ui.select label="Type" wire:model.defer="form.asset_type">
                    <option value="">Select type</option>
                    @foreach ($assetTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input label="Serial Number" wire:model.defer="form.serial_number" class="mono" />
                <x-assets.model-select :models="$models" />

                <x-ui.input label="IMEI" wire:model.defer="form.imei" class="mono" inputmode="numeric" />
                <x-ui.select label="Assigned To" wire:model.defer="form.assigned_to_employee_id">
                    <option value="">Unassigned</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->full_name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input label="Previous Assigned" id="f-previous-assigned" :value="$previousAssignedLabel ?: 'None'" disabled :error="false" />
                <x-ui.select label="Status" wire:model.defer="form.status">
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select label="Location (District)" wire:model.defer="form.district_id">
                    <option value="">Select location</option>
                    @foreach ($formDistricts as $district)
                        <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="User Phone Number" type="tel" wire:model.defer="form.user_phone_number" />

                <x-assets.actor-region :region="$actorRegion" />
                <x-ui.input label="Device Phone Number" type="tel" wire:model.defer="form.device_phone_number" />
            </div>

            <x-slot:footer>
                <button type="button" wire:click="closeForm" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="save" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingAssetId ? 'Update Device' : 'Create Device' }}
                </button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($mdmPromptDevice)
        @if ($mdmPromptAction === 'start')
            <x-ui.modal title="Start Lost Mode on this phone?" description="This phone is managed by MDM. Lost Mode locks it and shows a message." close="dismissMdmPrompt()" icon="map-pin" tone="danger">
                <x-assets.mdm-identity :device="$mdmPromptDevice" />
                <div class="ui-form-grid">
                    <div class="span-2"><x-ui.textarea label="Message shown on the phone" wire:model="mdmPromptMessage" rows="3" /></div>
                    <div class="span-2"><x-ui.input label="Phone number to call" type="tel" wire:model="mdmPromptPhone" /></div>
                </div>
                @error('message') <p class="ui-error">{{ $message }}</p> @enderror
                @error('command') <p class="ui-error">{{ $message }}</p> @enderror
                <x-slot:footer>
                    <button type="button" wire:click="dismissMdmPrompt" class="btn btn-secondary">Not now</button>
                    <button type="button" wire:click="confirmMdmPrompt" class="btn btn-danger-solid" wire:loading.attr="disabled" wire:target="confirmMdmPrompt">Start Lost Mode</button>
                </x-slot:footer>
            </x-ui.modal>
        @else
            <x-ui.modal title="Stop Lost Mode on this phone?" description="The phone is back. Stop Lost Mode so it can be used again." close="dismissMdmPrompt()" icon="circle-check">
                <x-assets.mdm-identity :device="$mdmPromptDevice" />
                @error('command') <p class="ui-error">{{ $message }}</p> @enderror
                <x-slot:footer>
                    <button type="button" wire:click="dismissMdmPrompt" class="btn btn-secondary">Not now</button>
                    <button type="button" wire:click="confirmMdmPrompt" class="btn btn-primary" wire:loading.attr="disabled" wire:target="confirmMdmPrompt">Stop Lost Mode</button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    @endif
</div>
