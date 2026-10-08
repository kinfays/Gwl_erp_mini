<div>
    <x-ui.page-header title="My equipment" description="Fire extinguishers and first aid kits you are responsible for. Open one to record its check." />

    <x-ui.card title="Fire extinguishers" :padded="false" class="dash-row">
        <x-ui.table label="My fire extinguishers">
            <x-slot:head><tr><th>Asset code</th><th>Where</th><th>Last check</th><th>State</th></tr></x-slot:head>
            @forelse ($extinguishers as $unit)
                @php $state = $unit->state(); @endphp
                <tr wire:key="hs-my-ext-{{ $unit->id }}">
                    <td><a class="mono" href="{{ route('health_safety.extinguishers.show', $unit) }}">{{ $unit->asset_code }}</a></td>
                    <td>{{ $unit->locationLabel() }}</td>
                    <td class="nowrap cell-muted">{{ $unit->last_checked_on?->format('d M Y') ?? 'Never' }}</td>
                    <td><x-ui.status-pill domain="hs-extinguisher" :status="$state ?? $unit->status" :label="$state ? \App\Models\HsFireExtinguisher::stateLabel($state) : \App\Models\HsFireExtinguisher::STATUSES[$unit->status]" /></td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="4" icon="inbox" title="No extinguishers are assigned to you." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="First aid kits" :padded="false" class="dash-row">
        <x-ui.table label="My first aid kits">
            <x-slot:head><tr><th>Asset code</th><th>Where</th><th>Last check</th><th>State</th></tr></x-slot:head>
            @forelse ($kits as $kit)
                @php $state = $kit->state(); @endphp
                <tr wire:key="hs-my-kit-{{ $kit->id }}">
                    <td><a class="mono" href="{{ route('health_safety.kits.show', $kit) }}">{{ $kit->asset_code }}</a></td>
                    <td>{{ $kit->locationLabel() }}</td>
                    <td class="nowrap cell-muted">{{ $kit->last_checked_on?->format('d M Y') ?? 'Never' }}</td>
                    <td><x-ui.status-pill domain="hs-kit" :status="$state ?? $kit->status" :label="$state ? \App\Models\HsFirstAidKit::stateLabel($state) : \App\Models\HsFirstAidKit::STATUSES[$kit->status]" /></td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="4" icon="inbox" title="No kits are assigned to you." />
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
