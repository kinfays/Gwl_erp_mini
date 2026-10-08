<div>
    <x-ui.page-header :title="$unit->asset_code" :description="$unit->typeLabel().($unit->capacity ? ' '.$unit->capacity : '').' at '.$unit->locationLabel()">
        <x-slot:actions>
            @if ($canManage)
                <x-ui.button :href="route('health_safety.labels.extinguishers', ['id' => $unit->id])" icon="qr-code">Print label</x-ui.button>
                <x-ui.button :href="route('health_safety.extinguishers.edit', $unit)" icon="pencil">Edit</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($unit->status === 'decommissioned')
        <x-ui.alert tone="warning" class="dash-row">This unit is decommissioned. It is kept for its history, and no more checks can be recorded on it.</x-ui.alert>
    @endif

    <div class="ui-tags dash-row">
        <x-ui.status-pill domain="hs-extinguisher" :status="$state ?? $unit->status" :label="$state ? \App\Models\HsFireExtinguisher::stateLabel($state) : $statuses[$unit->status]" />
        @if ($state && $unit->status !== 'in_service')<x-ui.badge>{{ $statuses[$unit->status] }}</x-ui.badge>@endif
        @if ($unit->vehicle_id)<x-ui.badge>In a vehicle</x-ui.badge>@endif
    </div>

    @error('status')<x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert>@enderror

    <x-ui.card title="Dates" class="dash-row">
        <dl class="ui-form-grid">
            <div><dt class="ui-label">Expires</dt><dd>{{ $unit->expiry_date?->format('d M Y') ?? 'Not set' }}</dd></div>
            <div><dt class="ui-label">Last serviced</dt><dd>{{ $unit->last_serviced_on?->format('d M Y') ?? 'Never' }}</dd></div>
            <div><dt class="ui-label">Next service due</dt><dd>{{ $unit->next_service_due?->format('d M Y') ?? 'Not set' }}</dd></div>
            <div><dt class="ui-label">Last hydrostatic test</dt><dd>{{ $unit->last_hydro_test_on?->format('d M Y') ?? 'Never' }}</dd></div>
            <div><dt class="ui-label">Next hydrostatic test due</dt><dd>{{ $unit->next_hydro_test_due?->format('d M Y') ?? 'Not set' }}</dd></div>
            <div>
                <dt class="ui-label">Last check</dt>
                <dd>
                    {{ $unit->last_checked_on?->format('d M Y') ?? 'Never checked' }}
                    @if ($unit->last_check_result)<x-ui.status-pill domain="hs-extinguisher" :status="$unit->last_check_result" />@endif
                    <span class="ui-hint">Next due {{ $nextCheckDue->format('d M Y') }}</span>
                </dd>
            </div>
        </dl>
    </x-ui.card>

    <x-ui.card title="Details" class="dash-row">
        <dl class="ui-form-grid">
            <div>
                <dt class="ui-label">Where</dt>
                <dd>
                    @if ($unit->vehicle_id)
                        @if ($canSeeVehicle)
                            <a href="{{ route('transport.vehicles') }}">Vehicle {{ $unit->vehicle?->number_plate }}</a>
                        @else
                            Vehicle {{ $unit->vehicle?->number_plate ?? '(removed)' }}
                        @endif
                    @else
                        {{ $unit->site?->name }}
                    @endif
                    @if ($unit->location_detail)<span class="cell-muted">, {{ $unit->location_detail }}</span>@endif
                </dd>
            </div>
            <div><dt class="ui-label">District</dt><dd>{{ $unit->district?->district_name ?? '—' }} <span class="cell-muted">{{ $unit->region?->region_name }}</span></dd></div>
            <div><dt class="ui-label">Serial number</dt><dd>{{ $unit->serial_number ?? '—' }}</dd></div>
            <div><dt class="ui-label">Manufacturer</dt><dd>{{ $unit->manufacturer ?? '—' }}@if ($unit->manufactured_on) <span class="cell-muted">made {{ $unit->manufactured_on->format('M Y') }}</span>@endif</dd></div>
            <div><dt class="ui-label">Responsible person</dt><dd>{{ $unit->responsible?->full_name ?? '—' }}</dd></div>
            @if ($unit->notes)<div class="span-2"><dt class="ui-label">Notes</dt><dd style="white-space:pre-line">{{ $unit->notes }}</dd></div>@endif
            @if ($unit->status === 'decommissioned')
                <div class="span-2"><dt class="ui-label">Decommissioned</dt><dd>{{ $unit->decommissioned_on?->format('d M Y') }}: {{ $unit->decommission_reason }}</dd></div>
            @endif
        </dl>
    </x-ui.card>

    @if ($canCheck || $canManage)
        <div class="ui-form-actions dash-row">
            @if ($canCheck)<x-ui.button variant="primary" icon="clipboard-check" wire:click="openPanel('check')">Record check</x-ui.button>@endif
            @if ($canManage)
                <x-ui.button icon="wrench" wire:click="openPanel('service')">Record service</x-ui.button>
                @if ($unit->status === 'in_service')
                    <x-ui.button wire:click="setStatus('out_for_service')">Out for service</x-ui.button>
                    <x-ui.button wire:click="setStatus('discharged')">Discharged</x-ui.button>
                @else
                    <x-ui.button wire:click="setStatus('in_service')">Back in service</x-ui.button>
                    @if ($unit->status === 'out_for_service')<x-ui.button wire:click="setStatus('discharged')">Discharged</x-ui.button>@endif
                @endif
                <x-ui.button variant="danger" wire:click="openPanel('decommission')">Decommission</x-ui.button>
            @endif
        </div>
    @endif

    {{-- Record check: one screen, large switches, usable one-handed --}}
    @if ($panel === 'check')
        <x-ui.card title="Record a check" description="Switch off anything that is not right. If something is, say what in the note." class="dash-row">
            <form wire:submit="recordCheck" class="ui-stack hs-check" novalidate>
                @foreach ($checkPoints as $field => $label)
                    <x-ui.toggle :label="$label" wire:model="check.{{ $field }}" />
                @endforeach
                <x-ui.input type="date" label="Date of the check" wire:model="check.checked_on" required />
                <x-ui.textarea label="Note" wire:model="check.notes" rows="2" error="notes" />
                <div class="ui-form-actions">
                    <x-ui.button wire:click="closePanel">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading="recordCheck">Save check</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if ($panel === 'service')
        <x-ui.card title="Record a service" description="This brings the unit's dates up to date and puts it back in service if it was out." class="dash-row">
            <form wire:submit="recordService" class="ui-stack" novalidate>
                <div class="ui-form-grid">
                    <x-ui.select label="Kind of service" wire:model="service.service_type" required>
                        @foreach ($serviceTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </x-ui.select>
                    <x-ui.input type="date" label="Serviced on" wire:model="service.serviced_on" required />
                    <x-ui.input label="Done by (vendor)" wire:model="service.vendor" />
                    <x-ui.input type="date" label="New expiry date (if changed)" wire:model="service.new_expiry_date" error="new_expiry_date" />
                    <x-ui.input type="date" label="Next service due" wire:model="service.next_service_due" error="next_service_due" hint="Left blank: {{ $serviceMonths }} months after the service." />
                    @if (($service['service_type'] ?? '') === 'hydro_test')
                        <x-ui.input type="date" label="Next hydrostatic test due" wire:model="service.next_hydro_test_due" error="next_hydro_test_due" hint="Left blank: {{ $hydroYears }} years after the test. The real interval depends on the type: confirm it." />
                    @endif
                    <x-ui.field label="Certificate (PDF, JPG or PNG)" for="hs-cert" error="certificate" class="span-2" hint="At most {{ $maxMb }} MB. Kept privately: only people who may see this unit can open it.">
                        <input id="hs-cert" type="file" class="form-input" wire:model="certificate" accept="application/pdf,image/jpeg,image/png">
                    </x-ui.field>
                    <div class="span-2"><x-ui.textarea label="Notes" wire:model="service.notes" rows="2" /></div>
                </div>
                <div class="ui-form-actions">
                    <x-ui.button wire:click="closePanel">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading="recordService">Save service</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if ($panel === 'decommission')
        <x-ui.card title="Decommission this extinguisher" description="It leaves every list and alert. Its history stays." class="dash-row">
            <div class="ui-stack">
                <x-ui.textarea label="Reason" wire:model="reason" rows="2" error="reason" required />
                <div class="ui-form-actions">
                    <x-ui.button wire:click="closePanel">Cancel</x-ui.button>
                    <x-ui.button variant="danger" wire:click="decommission" loading="decommission">Decommission</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card title="Check history" :padded="false" class="dash-row">
        <x-ui.table label="Checks">
            <x-slot:head><tr><th>Date</th><th>By</th><th>Result</th><th>Notes</th></tr></x-slot:head>
            @forelse ($checks as $entry)
                <tr wire:key="hs-xc-{{ $entry->id }}">
                    <td class="nowrap">{{ $entry->checked_on->format('d M Y') }}</td>
                    <td>{{ $entry->checker?->full_name }}</td>
                    <td>
                        <x-ui.status-pill domain="hs-extinguisher" :status="$entry->result" />
                        @if ($entry->result === 'fail')<span class="ui-person-sub">{{ implode(', ', $entry->failedPoints()) }}</span>@endif
                    </td>
                    <td>{{ $entry->notes }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="4" icon="clipboard-list" title="No checks recorded yet." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    @if ($fullView)
    <x-ui.card title="Service history" :padded="false" class="dash-row">
        <x-ui.table label="Services">
            <x-slot:head><tr><th>Date</th><th>Kind</th><th>Done by</th><th>Certificate</th><th>Notes</th></tr></x-slot:head>
            @forelse ($services as $entry)
                <tr wire:key="hs-xs-{{ $entry->id }}">
                    <td class="nowrap">{{ $entry->serviced_on->format('d M Y') }}</td>
                    <td>{{ $entry->typeLabel() }}@if ($entry->new_expiry_date) <span class="ui-person-sub">new expiry {{ $entry->new_expiry_date->format('d M Y') }}</span>@endif</td>
                    <td>{{ $entry->vendor }}</td>
                    <td>@if ($entry->certificate_path)<a href="{{ route('health_safety.equipment-files.show', $entry) }}" target="_blank" rel="noopener">{{ $entry->certificate_name }}</a>@else<span class="cell-muted">None</span>@endif</td>
                    <td>{{ $entry->notes }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" icon="wrench" title="No services recorded yet." />
            @endforelse
        </x-ui.table>
    </x-ui.card>
    @endif
</div>
