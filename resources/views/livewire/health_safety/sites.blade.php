<div>
    <x-ui.page-header title="Sites" description="Offices, pay points and depots that reports and (later) equipment are tied to.">
        <x-slot:actions>
            <x-ui.button variant="primary" icon="plus" wire:click="create">Add a site</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        <x-ui.card :title="$editingId ? 'Edit site' : 'New site'" class="dash-row">
            <form wire:submit="save" class="ui-stack" novalidate>
                <div class="ui-form-grid">
                    <x-ui.input label="Name" wire:model="name" required />
                    <x-ui.select label="Kind" wire:model="kind" required>
                        @foreach ($kinds as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                    @if ($seesAll)
                        <x-ui.select label="Region" wire:model.live="regionId" required>
                            <option value="">Select region</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                            @endforeach
                        </x-ui.select>
                    @endif
                    <x-ui.select label="District" wire:model="districtId" hint="Pay points need one, so reporters can find them.">
                        <option value="">None</option>
                        @foreach ($districts as $district)
                            <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Address (optional)" wire:model="address" class="span-2" />
                    <div class="span-2">
                        <x-ui.checkbox label="This is a PPE store" description="PPE stock can be received into it and issued from it. Staff PPE is taken from a store." wire:model="isPpeStore" />
                        @if ($isPpeStore && ! in_array($kind, [\App\Models\HsSite::KIND_REGIONAL_OFFICE, \App\Models\HsSite::KIND_HEAD_OFFICE], true))
                            <p class="ui-hint">PPE stores are normally the regional office.</p>
                        @endif
                        @error('isPpeStore')<p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>@enderror
                    </div>
                </div>
                <div class="ui-form-actions">
                    <x-ui.button wire:click="cancel">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading="save">Save site</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :padded="false" class="dash-row">
        <div class="ui-toolbar" role="search" aria-label="Filter sites">
            <input type="search" class="form-input" wire:model.live.debounce.300ms="search" placeholder="Search by name" aria-label="Search sites">
            <x-ui.checkbox label="Show deactivated" wire:model.live="showInactive" />
            <x-ui.button size="sm" icon="qr-code" wire:click="printPosters" :disabled="count($selectedSites) === 0">Print posters for selected ({{ count($selectedSites) }})</x-ui.button>
        </div>
        <p class="ui-hint" style="padding:0 1rem">Posters open <strong>{{ $qrBase }}</strong> (the report form with the site already chosen). Print them from production only: the address is part of the code.</p>
        @error('posters')<x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert>@enderror

        <x-ui.table label="Sites">
            <x-slot:head>
                <tr>
                    <th>Name</th>
                    <th>Kind</th>
                    <th>Region</th>
                    <th>District</th>
                    <th>Status</th>
                    <th>Poster</th>
                    <th class="actions"><span class="sr-only-text">Actions</span></th>
                </tr>
            </x-slot:head>
            @forelse ($sites as $site)
                <tr wire:key="hs-site-{{ $site->id }}">
                    <td>{{ $site->name }}@if ($site->address)<br><span class="ui-person-sub">{{ $site->address }}</span>@endif</td>
                    <td>{{ $site->kindLabel() }}@if ($site->is_ppe_store) <x-ui.badge tone="primary">PPE store</x-ui.badge>@endif</td>
                    <td>{{ $site->region?->region_name }}</td>
                    <td>{{ $site->district?->district_name ?? '—' }}</td>
                    <td><x-ui.status-pill domain="account" :status="$site->is_active ? 'active' : 'inactive'" :label="$site->is_active ? 'Active' : 'Deactivated'" /></td>
                    <td>@if ($site->is_active)<label><input type="checkbox" wire:model.live="selectedSites" value="{{ $site->id }}" aria-label="Select {{ $site->name }} for a poster"></label>@endif</td>
                    <td class="actions">
                        <x-ui.button size="sm" wire:click="edit({{ $site->id }})">Edit</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" wire:click="toggleActive({{ $site->id }})">{{ $site->is_active ? 'Deactivate' : 'Reactivate' }}</x-ui.button>
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="map-pin" title="No sites yet.">
                    <x-ui.button size="sm" wire:click="create">Add the first site</x-ui.button>
                </x-ui.empty-row>
            @endforelse

            @if ($sites->hasPages())
                <x-slot:footer>
                    <div class="pager-end">{{ $sites->links() }}</div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
