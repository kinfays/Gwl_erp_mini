<div>
    <x-ui.page-header title="PPE gaps" description="Staff against the PPE their job title is entitled to. Only job titles with entitlements are checked.">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button :href="route('health_safety.export', ['report' => 'ppe-gaps'] + $exportFilters)" icon="file-spreadsheet">Excel</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Staff with a gap" :value="$summary['gap_employees']" icon="user-x" :tone="$summary['gap_employees'] > 0 ? 'danger' : 'muted'" meta="Missing, overdue or short" :href="route('health_safety.ppe.gaps', ['state' => 'gap'])" />
        <x-ui.stat-tile label="Missing" :value="$summary['by_state']['missing']" icon="circle-x" :tone="$summary['by_state']['missing'] > 0 ? 'danger' : 'muted'" meta="Hold none of it" :href="route('health_safety.ppe.gaps', ['state' => 'missing'])" />
        <x-ui.stat-tile label="Overdue" :value="$summary['by_state']['overdue']" icon="triangle-alert" :tone="$summary['by_state']['overdue'] > 0 ? 'danger' : 'muted'" meta="Past replacement" :href="route('health_safety.ppe.gaps', ['state' => 'overdue'])" />
        <x-ui.stat-tile label="Replacement due" :value="$summary['by_state']['replacement_due']" icon="hourglass" :tone="$summary['by_state']['replacement_due'] > 0 ? 'warning' : 'muted'" meta="Within {{ \App\Services\HealthSafety\HealthSafetySettings::value('hs_expiry_warning_days') }} days" :href="route('health_safety.ppe.gaps', ['state' => 'replacement_due'])" />
    </div>

    @if ($summary['titles_without_entitlements'] > 0)
        <x-ui.alert tone="info" class="dash-row">
            {{ $summary['titles_without_entitlements'] }} job title{{ $summary['titles_without_entitlements'] === 1 ? ' has' : 's have' }} staff here but no PPE entitlements, so {{ $summary['titles_without_entitlements'] === 1 ? 'it is' : 'they are' }} not checked.
            @if ($canSetUp)<a href="{{ route('health_safety.ppe.entitlements') }}">Set up entitlements</a>.@endif
        </x-ui.alert>
    @endif

    <x-ui.card :padded="false" class="dash-row">
        <div class="ui-toolbar" role="search" aria-label="Filter gaps">
            <input type="search" class="form-input" wire:model.live.debounce.300ms="search" placeholder="Staff ID or name" aria-label="Search staff">
            <select class="form-input" wire:model.live="state" aria-label="State">
                <option value="">Any state</option>
                <option value="gap">Any gap (missing, overdue, short)</option>
                @foreach ($states as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <select class="form-input" wire:model.live="typeId" aria-label="PPE type">
                <option value="">All PPE</option>
                @foreach ($types as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach
            </select>
            <select class="form-input" wire:model.live="jobTitleId" aria-label="Job title">
                <option value="">All job titles</option>
                @foreach ($jobTitles as $title)<option value="{{ $title->id }}">{{ $title->job_title_name }}</option>@endforeach
            </select>
            <select class="form-input" wire:model.live="districtId" aria-label="District">
                <option value="">All districts</option>
                @foreach ($districts as $district)<option value="{{ $district->id }}">{{ $district->district_name }}</option>@endforeach
            </select>
            <x-ui.button size="sm" variant="ghost" wire:click="resetFilters">Clear</x-ui.button>
        </div>

        <x-ui.table label="PPE gaps" pin-first>
            <x-slot:head>
                <tr><th>Staff</th><th>Job title</th><th>District</th><th>PPE</th><th class="num">Entitled</th><th class="num">Holds</th><th class="num">In date</th><th>Next due</th><th>State</th></tr>
            </x-slot:head>
            @forelse ($page as $row)
                <tr wire:key="hs-gap-{{ $row['employee_id'] }}-{{ $row['ppe_type_id'] }}">
                    <td>{{ $row['name'] }}<br><span class="ui-person-sub mono">{{ $row['staff_id'] }}</span></td>
                    <td>{{ $row['job_title'] }}</td>
                    <td>{{ $row['district'] }}</td>
                    <td>{{ $row['type'] }}</td>
                    <td class="num">{{ $row['entitled'] }}</td>
                    <td class="num">{{ $row['held'] }}</td>
                    <td class="num">{{ $row['in_date'] }}</td>
                    <td class="nowrap">{{ $row['next_due']?->format('d M Y') ?? '—' }}</td>
                    <td><x-ui.status-pill domain="hs-ppe-state" :status="$row['state']" /></td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="9" icon="circle-check" title="Nothing matches these filters.">
                    <x-ui.button size="sm" wire:click="resetFilters">Clear filters</x-ui.button>
                </x-ui.empty-row>
            @endforelse

            @if ($page->hasPages())
                <x-slot:footer><div class="pager-end">{{ $page->withQueryString()->links() }}</div></x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
