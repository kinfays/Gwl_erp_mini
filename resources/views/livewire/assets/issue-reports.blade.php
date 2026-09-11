<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Issue Reporting</h2>
            <p>Capture field issues and resolution lifecycle per district or region.</p>
        </div>
        <div class="ph-right">
            <button type="button" wire:click="openCreate" class="btn btn-primary">New Report</button>
        </div>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Reported Issues</span>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="text" wire:model.live="search" class="form-input" placeholder="Search issue, reason, linked asset">
                <select wire:model.live="status" class="form-input">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
                <select wire:model.live="type" class="form-input">
                    <option value="">All issue types</option>
                    @foreach ($typeOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Asset</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Date Solved</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($reports as $report)
                    <tr>
                        <td>
                            <div>{{ $report->title }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ \Illuminate\Support\Str::limit($report->reason, 70) ?: '-' }}</div>
                        </td>
                        <td>{{ $report->issue_type }}</td>
                        <td>{{ $report->asset?->asset_name ?: 'Unlinked' }}</td>
                        <td>
                            <div>{{ $report->district?->district_name ?: '-' }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ $report->region?->region_name ?: '-' }}</div>
                        </td>
                        <td>
                            <span class="pill {{ $report->status === 'Resolved' ? 'p-g' : ($report->status === 'In Progress' ? 'p-a' : 'p-d') }}">
                                {{ $report->status }}
                            </span>
                        </td>
                        <td>{{ $report->date_solved?->format('d M Y') ?: '-' }}</td>
                        <td><button type="button" wire:click="openEdit({{ $report->id }})" class="actn">Edit</button></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align:center;color:var(--color-text-secondary);padding:20px">
                            No issue reports found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="display:flex;justify-content:flex-end;padding:10px 14px;border-top:0.5px solid var(--color-border-tertiary)">
            {{ $reports->links() }}
        </div>
    </div>

    @if ($showForm)
        <div class="letter-panel-backdrop">
            <div class="visitor-signature-modal" style="max-width: 860px;">
                <div class="pg-head">
                    <span class="pg-title">{{ $editingReportId ? 'Edit Report' : 'New Issue Report' }}</span>
                    <button type="button" wire:click="closeForm" class="actn">Close</button>
                </div>

                <div style="padding:14px">
                    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px">
                        <div>
                            <label class="form-label">Title</label>
                            <input type="text" wire:model.defer="form.title" class="form-input">
                            @error('form.title') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label">Issue Type</label>
                            <select wire:model.defer="form.issue_type" class="form-input">
                                <option value="">Select type</option>
                                @foreach ($typeOptions as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                            @error('form.issue_type') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label">Status</label>
                            <select wire:model.defer="form.status" class="form-input">
                                <option value="Open">Open</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Resolved">Resolved</option>
                                <option value="Closed">Closed</option>
                            </select>
                            @error('form.status') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label">Linked Asset</label>
                            <select wire:model.defer="form.linked_asset_id" class="form-input">
                                <option value="">Unlinked</option>
                                @foreach ($assets as $asset)
                                    <option value="{{ $asset->id }}">{{ $asset->asset_name }} ({{ $asset->serial_number ?: 'No serial' }})</option>
                                @endforeach
                            </select>
                            @error('form.linked_asset_id') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label">Region</label>
                            <select wire:model.defer="form.reporting_region_id" class="form-input" @if ($regionLocked) disabled @endif>
                                <option value="">Select region</option>
                                @foreach ($regions as $region)
                                    <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                                @endforeach
                            </select>
                            @error('form.reporting_region_id') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label">District</label>
                            <select wire:model.defer="form.reporting_district_id" class="form-input">
                                <option value="">Select district</option>
                                @foreach ($districts as $district)
                                    <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                                @endforeach
                            </select>
                            @error('form.reporting_district_id') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="form-label">Date Solved</label>
                            <input type="date" wire:model.defer="form.date_solved" class="form-input">
                            @error('form.date_solved') <div class="txt-err">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div style="margin-top:10px">
                        <label class="form-label">Reason / Details</label>
                        <textarea rows="4" wire:model.defer="form.reason" class="form-input"></textarea>
                        @error('form.reason') <div class="txt-err">{{ $message }}</div> @enderror
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">
                        <button type="button" wire:click="closeForm" class="btn">Cancel</button>
                        <button type="button" wire:click="save" class="btn btn-primary">
                            {{ $editingReportId ? 'Update' : 'Save' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

