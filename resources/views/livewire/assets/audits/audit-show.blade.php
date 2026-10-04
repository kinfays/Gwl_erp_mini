<div>
    <x-ui.page-header :title="$audit->title" :description="$audit->scopeSummary().' · started '.$audit->started_at?->format('d M Y').' by '.($audit->startedBy?->full_name ?: 'unknown')">
        <x-slot:actions>
            <a href="{{ route('assets.audits') }}" class="btn btn-secondary">All audits</a>
            @if ($audit->isCompleted() && $canExport)
                <a href="{{ route('assets.audits.export.excel', $audit) }}" class="btn btn-secondary">
                    <x-ui.icon name="file-spreadsheet" />
                    Excel
                </a>
                <a href="{{ route('assets.audits.export.pdf', $audit) }}" class="btn btn-secondary">
                    <x-ui.icon name="file-text" />
                    PDF
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($audit->isCompleted())
        <div class="ui-stat-grid dash-row">
            <x-ui.stat-tile label="Reconciliation rate" :value="$audit->reconciliation_rate.'%'" icon="clipboard-check" tone="success" />
            <x-ui.stat-tile label="Matched" :value="$summary['matched']" icon="circle-check" tone="success" />
            <x-ui.stat-tile label="Mismatch" :value="$summary['mismatch']" icon="triangle-alert" tone="warning" />
            <x-ui.stat-tile label="Not found" :value="$summary['not_found']" icon="triangle-alert" tone="danger" />
        </div>

        <x-ui.card title="Audit Result" :description="'Completed '.$audit->completed_at?->format('d M Y, H:i').'. Read only.'" :padded="false">
            <x-ui.table label="Completed audit lines" :sticky="false">
                <x-slot:head>
                    <tr>
                        @foreach ($columns as $heading)
                            <th>{{ $heading }}</th>
                        @endforeach
                    </tr>
                </x-slot:head>
                @foreach ($preview as $row)
                    <tr wire:key="preview-{{ $row['no'] }}">
                        @foreach (array_keys($columns) as $key)
                            <td>{{ $row[$key] }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </x-ui.table>
            @if ($summary['total'] > $preview->count())
                <p class="ui-hint" style="padding: 0.75rem 1rem;">Showing the first {{ $preview->count() }} of {{ $summary['total'] }} lines. The exports contain all of them.</p>
            @endif
        </x-ui.card>
    @else
        <x-ui.card :padded="false">
            <div class="ui-toolbar" role="search" aria-label="Filter audit lines">
                <p class="toolbar-grow" role="status">
                    <strong>{{ $summary['verified'] }} of {{ $summary['total'] }}</strong> lines verified
                    &middot; {{ $summary['matched'] }} matched, {{ $summary['mismatch'] }} mismatch, {{ $summary['not_found'] }} not found
                </p>
                <select wire:model.live="category" class="form-input" aria-label="Device category">
                    <option value="">All categories</option>
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="resultFilter" class="form-input" aria-label="Result">
                    <option value="">Any result</option>
                    <option value="pending">Pending</option>
                    <option value="matched">Matched</option>
                    <option value="mismatch">Mismatch</option>
                    <option value="not_found">Not found</option>
                </select>
                <span @if ($summary['pending'] > 0) title="{{ $summary['pending'] }} line(s) are still pending. Verify every asset before completing." @endif>
                    <button type="button" wire:click="completeAudit" class="btn btn-primary" @disabled($summary['pending'] > 0) wire:loading.attr="disabled" wire:target="completeAudit">
                        Complete audit
                    </button>
                </span>
            </div>

            @error('audit') <p class="ui-error" style="padding: 0 1rem;">{{ $message }}</p> @enderror
            @error('result') <p class="ui-error" style="padding: 0 1rem;">{{ $message }}</p> @enderror

            <x-ui.table label="Audit lines" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Asset</th>
                        <th>Expected</th>
                        <th>Result</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($lines as $line)
                    <tr wire:key="line-{{ $line->id }}">
                        <td>
                            <span class="ui-cell-stack">
                                <a href="{{ route('assets.show', $line->ict_asset_id) }}" class="ui-person-name">{{ $line->asset?->asset_name }}</a>
                                <span class="ui-person-sub mono">{{ $line->asset?->serial_number ?: 'No serial' }} &middot; {{ $line->asset?->asset_type }}{{ $line->asset?->assetModel ? ' · '.$line->asset->assetModel->name : '' }}</span>
                            </span>
                        </td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ $line->expected_status ?: 'No status' }} &middot; {{ $line->expectedEmployee?->full_name ?: 'Unassigned' }}</span>
                                <span class="ui-person-sub">{{ $line->expectedDistrict?->district_name ?: 'No district' }}</span>
                            </span>
                        </td>
                        <td>
                            @if ($line->result === 'matched')
                                <x-ui.status-pill tone="success" label="Matched" />
                            @elseif ($line->result === 'mismatch')
                                <span class="ui-cell-stack">
                                    <x-ui.status-pill tone="warning" label="Mismatch" />
                                    <span class="ui-person-sub">Found: {{ $line->actual_status ?: '—' }} &middot; {{ $line->actualEmployee?->full_name ?: 'Unassigned' }} &middot; {{ $line->actualDistrict?->district_name ?: 'No district' }}{{ $line->actual_location ? ' · '.$line->actual_location : '' }}</span>
                                    <span class="ui-person-sub">{{ $line->mismatch_reason }}</span>
                                    @if ($line->correction_applied)
                                        <span class="ui-person-sub">Correction applied</span>
                                    @endif
                                </span>
                            @elseif ($line->result === 'not_found')
                                <span class="ui-cell-stack">
                                    <x-ui.status-pill tone="danger" label="Not found" />
                                    <span class="ui-person-sub">{{ $line->mismatch_reason }}{{ $line->actual_location ? ' · last seen: '.$line->actual_location : '' }}</span>
                                </span>
                            @else
                                <x-ui.status-pill tone="muted" label="Pending" />
                            @endif
                        </td>
                        <td class="actions">
                            <div class="row-actions">
                                @unless ($line->correction_applied)
                                    <button type="button" wire:click="markMatched({{ $line->id }})" class="btn btn-ghost btn-sm">Matched</button>
                                    <button type="button" wire:click="openRecord({{ $line->id }}, 'mismatch')" class="btn btn-ghost btn-sm">Mismatch</button>
                                    <button type="button" wire:click="openRecord({{ $line->id }}, 'not_found')" class="btn btn-ghost btn-sm">Not found</button>
                                @endunless
                                @if ($line->result === 'mismatch' && ! $line->correction_applied)
                                    <button type="button" wire:click="applyCorrection({{ $line->id }})" class="btn btn-secondary btn-sm"
                                        wire:confirm="Update the asset record to what was found?">Apply correction</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="4" icon="clipboard-list" title="No lines match these filters." />
                @endforelse

                <x-slot:footer>
                    <p class="pager-summary">Showing {{ $lines->firstItem() ?? 0 }} - {{ $lines->lastItem() ?? 0 }} of {{ $lines->total() }} lines</p>
                    <div>{{ $lines->links() }}</div>
                </x-slot:footer>
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($recordingLineId)
        <x-ui.modal :title="$recordingResult === 'not_found' ? 'Asset not found' : 'Record mismatch'" close="cancelRecord()" size="lg" icon="triangle-alert" tone="warning">
            <div class="ui-form-grid">
                @if ($recordingResult === 'mismatch')
                    <x-ui.select label="Actual status" wire:model="actualStatus">
                        <option value="">Unchanged / unknown</option>
                        @foreach ($statusOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Actual holder" wire:model="actualEmployeeId">
                        <option value="">No one (unassigned)</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->full_name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Actual district" wire:model="actualDistrictId">
                        <option value="">No district</option>
                        @foreach ($districts as $district)
                            <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                        @endforeach
                    </x-ui.select>
                @endif
                <x-ui.input label="{{ $recordingResult === 'not_found' ? 'Last known location' : 'Location found' }}" wire:model="actualLocation" placeholder="e.g. Server room, 2nd floor" />
                <div class="span-2">
                    <x-ui.textarea label="Reason" wire:model="reason" rows="2" hint="Required: say what was found." />
                </div>
            </div>

            <x-slot:footer>
                <button type="button" wire:click="cancelRecord" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="saveRecord" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveRecord">Save result</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
