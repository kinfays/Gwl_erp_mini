<div>
    <x-ui.page-header title="KPI targets" description="The yearly target of each district for sales (m³), billing and collection (GH¢). The KPI page spreads a year's target evenly over its twelve months, and a year with no target has none: nothing carries over from the year before.">
        <x-slot:actions><a href="{{ route('commercial.kpis') }}" class="btn btn-secondary">Back to the KPIs</a></x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="Set targets" description="Type the annual target in each box you want to set or change; leave a box empty to leave it alone. The boxes show the targets already set for the year chosen." class="dash-row">
        <div class="ui-toolbar">
            <label for="year"><strong>Year</strong></label>
            <select id="year" wire:model.live="year" class="form-input">@foreach ($yearOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            @error('year')<span class="form-error">{{ $message }}</span>@enderror
        </div>

        <x-ui.table label="District targets" :sticky="false">
            <x-slot:head><tr><th>District</th>@foreach ($metrics as $key => $meta)<th class="num">{{ $meta['label'] }} ({{ $meta['unit'] }})</th>@endforeach</tr></x-slot:head>
            @forelse ($districts as $district)
                <tr wire:key="d-{{ $district->id }}">
                    <td>{{ $district->district_name }}</td>
                    @foreach ($metrics as $key => $meta)
                        <td class="num">
                            <input type="text" inputmode="decimal" wire:model="grid.{{ $district->id }}.{{ $key }}" class="form-input" style="width: 10rem; text-align: right" aria-label="{{ $meta['label'] }} target of {{ $district->district_name }}">
                            @error('grid.'.$district->id.'.'.$key)<span class="form-error">{{ $message }}</span>@enderror
                        </td>
                    @endforeach
                </tr>
            @empty
                <x-ui.empty-row :colspan="4" icon="map" title="No districts." />
            @endforelse
        </x-ui.table>

        <div class="dash-row"><button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Save the targets</button></div>
    </x-ui.card>

    <x-ui.card title="History of one district" class="dash-row">
        <select wire:model.live="historyDistrict" class="form-input" aria-label="District">
            <option value="">Choose a district…</option>
            @foreach ($districts as $district)<option value="{{ $district->id }}">{{ $district->district_name }}</option>@endforeach
        </select>

        @if ($historyDistrict !== '')
            <x-ui.table label="Target history" :sticky="false">
                <x-slot:head><tr><th>KPI</th><th>Year</th><th class="num">Annual target</th><th></th></tr></x-slot:head>
                @forelse ($history as $row)
                    <tr wire:key="h-{{ $row->id }}">
                        <td>{{ $metrics[$row->metric]['label'] ?? $row->metric }}</td>
                        <td>{{ $row->effective_from->format('Y') }}</td>
                        <td class="num">{{ number_format($row->value, 2) }}</td>
                        <td><button type="button" class="btn btn-ghost btn-sm" wire:click="delete({{ $row->id }})" wire:confirm="Remove this annual target? That year will then have no target for this KPI.">Remove</button></td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="4" icon="target" title="No targets set for this district yet." />
                @endforelse
            </x-ui.table>
        @endif
    </x-ui.card>
</div>
