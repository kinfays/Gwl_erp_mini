<div>
    <x-ui.page-header title="Asset Audits" description="Physical verification runs: confirm that what is on the ground matches the system.">
        <x-slot:actions>
            <button type="button" wire:click="openCreate" class="btn btn-primary">
                <x-ui.icon name="plus" />
                New Audit
            </button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padded="false">
        <x-ui.table label="Asset audits" pin-first>
            <x-slot:head>
                <tr>
                    <th>Audit</th>
                    <th>Scope</th>
                    <th>Status</th>
                    <th class="num">Reconciliation</th>
                    <th>Started</th>
                    <th class="actions"><span class="sr-only-text">Open</span></th>
                </tr>
            </x-slot:head>

            @forelse ($audits as $audit)
                <tr wire:key="audit-{{ $audit->id }}">
                    <td><a href="{{ route('assets.audits.show', $audit) }}"><span class="ui-person-name">{{ $audit->title }}</span></a></td>
                    <td>{{ $audit->scopeSummary() }}</td>
                    <td>
                        <x-ui.status-pill :tone="$audit->isCompleted() ? 'success' : 'warning'" :label="$audit->isCompleted() ? 'Completed' : 'In progress'" />
                    </td>
                    <td class="num">{{ $audit->reconciliation_rate !== null ? $audit->reconciliation_rate.'%' : '—' }}</td>
                    <td>
                        <span class="ui-cell-stack">
                            <span>{{ $audit->started_at?->format('d M Y') }}</span>
                            <span class="ui-person-sub">{{ $audit->startedBy?->full_name ?: 'Unknown' }}</span>
                        </span>
                    </td>
                    <td class="actions">
                        <a href="{{ route('assets.audits.show', $audit) }}" class="btn btn-ghost btn-sm">{{ $audit->isCompleted() ? 'View' : 'Continue' }}</a>
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="6" icon="clipboard-list" title="No audits yet." description="Start one to check assets against what is physically there." />
            @endforelse

            <x-slot:footer>
                <p class="pager-summary">{{ $audits->total() }} {{ \Illuminate\Support\Str::plural('audit', $audits->total()) }}</p>
                <div>{{ $audits->links() }}</div>
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>

    @if ($showForm)
        <x-ui.modal title="New Asset Audit" close="closeForm()" size="lg" icon="clipboard-list">
            <div class="ui-form-grid">
                <div class="span-2">
                    <x-ui.input label="Title" wire:model.defer="title" placeholder="e.g. Q4 2026 Head Office Stock Check" />
                </div>

                <x-ui.select label="Device category" wire:model.live="deviceCategory">
                    <option value="">All categories</option>
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select label="Region" wire:model.live="regionId" :disabled="! $seesAllRegions">
                    @if ($seesAllRegions)
                        <option value="">All regions</option>
                    @endif
                    @foreach ($regions as $region)
                        <option value="{{ $region->id }}" @selected(! $seesAllRegions)>{{ $region->region_name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select label="District" wire:model.live="districtId">
                    <option value="">All districts</option>
                    @foreach ($districts as $district)
                        <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                    @endforeach
                </x-ui.select>

                <div class="span-2">
                    @error('scope') <p class="ui-error">{{ $message }}</p> @enderror
                    <p class="ui-hint" role="status">This will create {{ $lineCount }} audit {{ \Illuminate\Support\Str::plural('line', $lineCount) }}, one for each asset in scope, as they are right now.</p>
                </div>
            </div>

            <x-slot:footer>
                <button type="button" wire:click="closeForm" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="create" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Start audit</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
