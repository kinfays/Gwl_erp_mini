<div>
    @php
        // One set of inputs serves both modes; each mode binds its own properties.
        $mode = $editingId ? 'edit-'.$editingId : 'new';
    @endphp

    <x-ui.page-header title="IP Ranges" description="Assigned IP ranges per district/region, used to validate Device IP / Management IP on save." />

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Range Directory" :description="$ranges->count().' ranges'" :padded="false">
            <x-ui.table label="IP ranges">
                <x-slot:head>
                    <tr>
                        <th>Label</th>
                        <th>Location</th>
                        <th>Range</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($ranges as $range)
                    <tr wire:key="ip-range-{{ $range->id }}" @class(['is-selected' => $editingId === $range->id])>
                        <td class="nowrap"><span class="ui-person-name">{{ $range->label }}</span></td>
                        <td>{{ $range->district?->district_name ?: ($range->region?->region_name ?: 'All locations') }}</td>
                        <td>
                            <span class="ui-cell-stack">
                                <span class="mono">{{ $range->start_ip }} – {{ $range->end_ip }}</span>
                                @if ($range->cidr)
                                    <span class="ui-person-sub mono">{{ $range->cidr }}</span>
                                @endif
                            </span>
                        </td>
                        <td><x-ui.status-pill domain="account" :status="$range->is_active ? 'Active' : 'Inactive'" /></td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" wire:click="edit({{ $range->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $range->label }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-ghost btn-sm btn-icon is-danger"
                                    title="Delete"
                                    aria-label="Delete {{ $range->label }}"
                                    x-data
                                    x-on:click.prevent="$dispatch('confirm-action', {
                                        title: 'Delete IP range?',
                                        message: @js('This will delete ' . $range->label . '.'),
                                        confirmLabel: 'Delete',
                                        variant: 'danger',
                                        action: () => $wire.delete({{ $range->id }})
                                    })"
                                >
                                    <x-ui.icon name="trash-2" />
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="5" icon="ethernet-port" title="No IP ranges configured." description="Add the first one with the form alongside." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="$editingId ? 'Edit IP Range' : 'Add IP Range'" class="manager-form">
            <div class="ui-stack">
                <x-ui.input
                    label="Label"
                    wire:model="{{ $editingId ? 'editingLabel' : 'label' }}"
                    wire:key="ip-range-label-{{ $mode }}"
                    placeholder="e.g. Accra West HQ LAN"
                    x-init="{{ $editingId ? '$nextTick(() => $el.focus())' : '' }}"
                />

                <x-ui.select label="Region" wire:model="{{ $editingId ? 'editingRegionId' : 'region_id' }}" wire:key="ip-range-region-{{ $mode }}">
                    <option value="">Any region</option>
                    @foreach ($regions as $region)
                        <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select label="District" wire:model="{{ $editingId ? 'editingDistrictId' : 'district_id' }}" wire:key="ip-range-district-{{ $mode }}">
                    <option value="">Any district</option>
                    @foreach ($districts as $district)
                        <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                    @endforeach
                </x-ui.select>

                <div class="ui-form-grid">
                    <x-ui.input label="Start IP" wire:model="{{ $editingId ? 'editingStartIp' : 'start_ip' }}" wire:key="ip-range-start-{{ $mode }}" placeholder="192.168.10.1" class="mono" inputmode="decimal" />
                    <x-ui.input label="End IP" wire:model="{{ $editingId ? 'editingEndIp' : 'end_ip' }}" wire:key="ip-range-end-{{ $mode }}" placeholder="192.168.10.254" class="mono" inputmode="decimal" />
                </div>

                <x-ui.input label="CIDR (optional)" wire:model="{{ $editingId ? 'editingCidr' : 'cidr' }}" wire:key="ip-range-cidr-{{ $mode }}" placeholder="192.168.10.0/24" class="mono" />

                <x-ui.textarea label="Notes" wire:model="{{ $editingId ? 'editingNotes' : 'notes' }}" wire:key="ip-range-notes-{{ $mode }}" rows="2" />

                @if ($editingId)
                    <x-ui.checkbox label="Active" wire:model="editingIsActive" id="f-ip-range-active" />
                @endif

                <div class="ui-form-actions">
                    @if ($editingId)
                        <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Cancel</button>
                        <button type="button" wire:click="update" class="btn btn-primary">Save</button>
                    @else
                        <button type="button" wire:click="save" class="btn btn-primary">
                            <x-ui.icon name="plus" />
                            Add Range
                        </button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
