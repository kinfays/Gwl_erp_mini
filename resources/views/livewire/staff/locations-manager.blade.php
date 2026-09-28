<div>
    <x-ui.page-header title="Locations" description="Manage district/location records and attach each one to a region." />

    @if (session('success'))
        <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
    @endif

    @error('district_name')
        <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert>
    @enderror

    @if ($regions->isEmpty())
        <x-ui.alert tone="warning">Add regions before creating locations.</x-ui.alert>
    @endif

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Location Directory" :description="$locations->count().' locations'" :padded="false">
            <x-ui.table label="Locations">
                <x-slot:head>
                    <tr>
                        <th>Location</th>
                        <th>Region</th>
                        <th class="num">Employees</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($locations as $location)
                    <tr wire:key="location-{{ $location->id }}" @class(['is-selected' => $editingId === $location->id])>
                        <td>{{ $location->district_name }}</td>
                        <td @class(['cell-muted' => ! $location->region])>{{ $location->region?->region_name ?? '-' }}</td>
                        <td class="num">{{ $location->employees_count }}</td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" wire:click="edit({{ $location->id }})" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $location->district_name }}">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-ghost btn-sm btn-icon is-danger"
                                    title="Delete"
                                    aria-label="Delete {{ $location->district_name }}"
                                    x-data
                                    x-on:click.prevent="$dispatch('confirm-action', {
                                        title: 'Delete location?',
                                        message: @js('This will delete ' . $location->district_name . ' if no employees are assigned.'),
                                        confirmLabel: 'Delete',
                                        variant: 'danger',
                                        action: () => $wire.delete({{ $location->id }})
                                    })"
                                >
                                    <x-ui.icon name="trash-2" />
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="4" icon="map-pin" title="No locations found." description="Add the first one with the form alongside." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="$editingId ? 'Edit Location' : 'Add Location'" class="manager-form">
            <div class="ui-stack">
                @if ($editingId)
                    <x-ui.select label="Region" wire:model="editingRegionId" wire:key="location-edit-region-{{ $editingId }}" x-init="$nextTick(() => $el.focus())">
                        <option value="">Select region</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Location Name" wire:model="editingName" placeholder="Enter location name" wire:key="location-edit-name-{{ $editingId }}" />
                @else
                    <x-ui.select label="Region" wire:model="region_id" wire:key="location-new-region">
                        <option value="">Select region</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Location Name" wire:model="district_name" placeholder="Enter location name" wire:key="location-new-name" />
                @endif

                <div class="ui-form-actions">
                    @if ($editingId)
                        <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Cancel</button>
                        <button type="button" wire:click="update" class="btn btn-primary">Save</button>
                    @else
                        <button type="button" wire:click="save" class="btn btn-primary" @disabled($regions->isEmpty())>
                            <x-ui.icon name="plus" />
                            Add Location
                        </button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
