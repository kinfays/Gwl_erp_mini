<div>
    <x-ui.page-header title="Customer list: categories, statuses and upload cadence" description="What the category and status codes in the files mean, and how often each district uploads. Changes are recorded in the audit log.">
        <x-slot:actions><a href="{{ route('commercial.customers.uploads') }}" class="btn btn-secondary">Back to uploads</a></x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert tone="warning" class="dash-row">
        The grouping of category codes below is a <strong>proposal</strong> until the Commercial team confirms it, and the meaning of the status codes TRFR, VACN, DISO and NFLO is <strong>not confirmed</strong>:
        until it is, they appear everywhere as their raw code. {{ $pending }} code(s) found in uploaded files are waiting for review.
    </x-ui.alert>

    <x-ui.card title="Category groups" description="Group each category code. Tick Confirmed once the grouping is agreed." :padded="false" class="dash-row">
        <x-ui.table label="Category groups" :sticky="true">
            <x-slot:head><tr><th>Code</th><th>Name</th><th>Group</th><th>Confirmed</th></tr></x-slot:head>
            @foreach ($categories as $id => $row)
                <tr wire:key="cat-{{ $id }}">
                    <td class="mono">{{ $codes[$id] ?? 'UNKNOWN' }}@if (in_array($id, $pendingIds, true))<x-ui.badge tone="warning">New in a file</x-ui.badge>@endif</td>
                    <td><input class="form-input" wire:model="categories.{{ $id }}.name" aria-label="Name of {{ $codes[$id] ?? 'unknown' }}"></td>
                    <td><select class="form-input" wire:model="categories.{{ $id }}.group" aria-label="Group of {{ $codes[$id] ?? 'unknown' }}">@foreach ($groups as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></td>
                    <td><input type="checkbox" wire:model="categories.{{ $id }}.confirmed" aria-label="Confirmed"></td>
                </tr>
            @endforeach
        </x-ui.table>
        <div class="dash-row"><button type="button" class="btn btn-primary" wire:click="saveCategories">Save categories</button></div>
    </x-ui.card>

    <x-ui.card title="Account statuses" description="'Billing' decides who counts as a billing account (ACTB), 'Active' who counts as active. A code whose meaning is not confirmed is shown by its raw code." :padded="false" class="dash-row">
        <x-ui.table label="Account statuses" :sticky="false">
            <x-slot:head><tr><th>Code</th><th>Meaning</th><th>Active</th><th>Billing</th><th>Meaning confirmed</th></tr></x-slot:head>
            @foreach ($statuses as $id => $row)
                <tr wire:key="st-{{ $id }}">
                    <td class="mono">{{ $statusCodes[$id] }}</td>
                    <td><input class="form-input" wire:model="statuses.{{ $id }}.label" aria-label="Meaning of {{ $statusCodes[$id] }}"></td>
                    <td><input type="checkbox" wire:model="statuses.{{ $id }}.active" aria-label="Active"></td>
                    <td><input type="checkbox" wire:model="statuses.{{ $id }}.billing" aria-label="Billing"></td>
                    <td><input type="checkbox" wire:model="statuses.{{ $id }}.confirmed" aria-label="Meaning confirmed"></td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.table label="Meter statuses" :sticky="false">
            <x-slot:head><tr><th>Meter status code</th><th>Meaning</th></tr></x-slot:head>
            @foreach ($meters as $id => $label)
                <tr wire:key="mt-{{ $id }}"><td class="mono">{{ $meterCodes[$id] }}</td><td><input class="form-input" wire:model="meters.{{ $id }}" aria-label="Meaning of meter status {{ $meterCodes[$id] }}"></td></tr>
            @endforeach
        </x-ui.table>
        <div class="dash-row"><button type="button" class="btn btn-primary" wire:click="saveStatuses">Save statuses</button></div>
    </x-ui.card>

    <x-ui.card title="Upload cadence" :description="'How often each district is expected to upload its customer list. Blank = the default ('.$default.'). The reminders and the overdue badge follow this.'" :padded="false" class="dash-row">
        <x-ui.table label="Upload cadence by district" :sticky="true">
            <x-slot:head><tr><th>District</th><th>Expected</th></tr></x-slot:head>
            @foreach ($districts as $id => $name)
                <tr wire:key="cd-{{ $id }}"><td>{{ $name }}</td>
                    <td><select class="form-input" wire:model="cadence.{{ $id }}" aria-label="Cadence of {{ $name }}"><option value="">Default ({{ $default }})</option>@foreach ($options as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></td></tr>
            @endforeach
        </x-ui.table>
        <div class="dash-row"><button type="button" class="btn btn-primary" wire:click="saveCadence">Save cadence</button></div>
    </x-ui.card>
</div>
