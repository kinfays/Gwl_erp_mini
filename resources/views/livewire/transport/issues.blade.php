<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Vehicle Issues</h2>
            <p>Driver issue reporting and transport manager workflow.</p>
        </div>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Report Issue</span>
        </div>
        <div style="padding:14px">
            @if ($availableVehicles->isNotEmpty())
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Vehicle</label>
                        <select class="form-input" wire:model="vehicleId">
                            <option value="">Select vehicle</option>
                            @foreach ($availableVehicles as $vehicle)
                                <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }} - {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                            @endforeach
                        </select>
                        @error('vehicleId') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Severity</label>
                        <select class="form-input" wire:model="form.severity">
                            @foreach ($severities as $option)
                                <option value="{{ $option }}">{{ str($option)->title() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Issue Types</label>
                        <select multiple class="form-input" wire:model="form.issue_types" style="min-height:105px">
                            @foreach ($issueTypes as $option)
                                <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                            @endforeach
                        </select>
                        @error('form.issue_types') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Photo</label>
                        <input type="file" class="form-input" wire:model="photo">
                        @error('photo') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Description</label>
                        <textarea class="form-input" rows="4" wire:model.defer="form.description"></textarea>
                        @error('form.description') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="submit" wire:loading.attr="disabled">Submit Issue</button>
                    </div>
                </div>
            @else
                <div class="empty-state">No active vehicle is assigned to your account.</div>
            @endif
        </div>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">{{ $canManage ? 'Issue Board' : 'My Issues' }}</span>
            <div class="ph-right">
                <select class="form-input" wire:model.live="status">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $option)
                        <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
                <select class="form-input" wire:model.live="severity">
                    <option value="">All severities</option>
                    @foreach ($severities as $option)
                        <option value="{{ $option }}">{{ str($option)->title() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Vehicle</th>
                    <th>Reported By</th>
                    <th>Types</th>
                    <th>Severity</th>
                    <th>Status</th>
                    <th>Reported</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($issues as $issue)
                    <tr>
                        <td>
                            <div>{{ $issue->vehicle?->number_plate }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ str($issue->description)->limit(80) }}</div>
                        </td>
                        <td>{{ $issue->reporter?->full_name ?? $issue->reporter?->email }}</td>
                        <td>{{ collect($issue->issue_types)->map(fn ($type) => str($type)->replace('_', ' ')->title())->join(', ') }}</td>
                        <td>
                            <span class="pill" style="background:{{ in_array($issue->severity, ['high','critical'], true) ? '#fcebeb' : '#faeeda' }};color:var(--color-text-primary)">
                                {{ str($issue->severity)->title() }}
                            </span>
                        </td>
                        <td>{{ str($issue->status)->replace('_', ' ')->title() }}</td>
                        <td>{{ $issue->reported_at?->format('d M Y H:i') }}</td>
                        <td>
                            @if ($canManage)
                                <select class="form-input" wire:change="updateStatus({{ $issue->id }}, $event.target.value)">
                                    @foreach ($statuses as $option)
                                        <option value="{{ $option }}" @selected($issue->status === $option)>{{ str($option)->replace('_', ' ')->title() }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty-state">No issues found.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px 14px">{{ $issues->links() }}</div>
    </div>
</div>
