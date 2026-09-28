<div>
    <x-ui.page-header title="Vehicle Issues" description="Driver issue reporting and transport manager workflow." />

    <x-ui.card title="Report Issue" class="dash-row">
        @if ($availableVehicles->isNotEmpty())
            <div class="ui-stack">
                <div class="ui-form-grid">
                    <x-ui.select label="Vehicle" wire:model="vehicleId" id="f-issue-vehicle" error="vehicleId">
                        <option value="">Select vehicle</option>
                        @foreach ($availableVehicles as $vehicle)
                            <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }} - {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Severity" wire:model="form.severity">
                        @foreach ($severities as $option)
                            <option value="{{ $option }}">{{ str($option)->title() }}</option>
                        @endforeach
                    </x-ui.select>

                    <fieldset class="chip-group span-2" @error('form.issue_types') aria-describedby="f-issue-types-error" @enderror>
                        <legend class="ui-label">Issue Types</legend>
                        <div class="chip-options">
                            @foreach ($issueTypes as $option)
                                <label class="chip-option">
                                    <input type="checkbox" value="{{ $option }}" wire:model="form.issue_types">
                                    <span>{{ str($option)->replace('_', ' ')->title() }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('form.issue_types')
                            <p class="ui-error" id="f-issue-types-error">
                                <x-ui.icon name="circle-alert" class="icon-sm" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </fieldset>

                    <x-ui.textarea label="Description" wire:model.defer="form.description" rows="4" />
                    <x-ui.field label="Photo" for="f-issue-photo" error="photo">
                        <input id="f-issue-photo" type="file" class="form-input" wire:model="photo">
                    </x-ui.field>
                </div>

                <div class="ui-form-actions">
                    <button type="button" class="btn btn-primary" wire:click="submit" wire:loading.attr="disabled">
                        <x-ui.icon name="send" />
                        Submit Issue
                    </button>
                </div>
            </div>
        @else
            <x-ui.empty-state icon="car" title="No active vehicle is assigned to your account." />
        @endif
    </x-ui.card>

    <x-ui.card :title="$canManage ? 'Issue Board' : 'My Issues'" :padded="false">
        <div class="ui-toolbar" role="search" aria-label="Filter issues">
            <select class="form-input" wire:model.live="status" aria-label="Status">
                <option value="">All statuses</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="severity" aria-label="Severity">
                <option value="">All severities</option>
                @foreach ($severities as $option)
                    <option value="{{ $option }}">{{ str($option)->title() }}</option>
                @endforeach
            </select>
        </div>

        <x-ui.table label="Vehicle issues" pin-first>
            <x-slot:head>
                <tr>
                    <th>Vehicle</th>
                    <th>Reported By</th>
                    <th>Types</th>
                    <th>Severity</th>
                    <th>Status</th>
                    <th>Reported</th>
                    @if ($canManage)
                        <th>Update Status</th>
                    @endif
                </tr>
            </x-slot:head>

            @forelse ($issues as $issue)
                <tr wire:key="vehicle-issue-{{ $issue->id }}">
                    <td>
                        <span class="ui-cell-stack">
                            <span class="ui-person-name mono">{{ $issue->vehicle?->number_plate }}</span>
                            <span class="ui-person-sub">{{ str($issue->description)->limit(80) }}</span>
                        </span>
                    </td>
                    <td class="nowrap">{{ $issue->reporter?->full_name ?? $issue->reporter?->email }}</td>
                    <td>
                        <span class="ui-tags">
                            @foreach (collect($issue->issue_types) as $type)
                                <x-ui.badge>{{ str($type)->replace('_', ' ')->title() }}</x-ui.badge>
                            @endforeach
                        </span>
                    </td>
                    <td><x-ui.status-pill domain="severity" :status="$issue->severity" :label="str($issue->severity)->title()" /></td>
                    <td><x-ui.status-pill domain="issue" :status="$issue->status" :label="str($issue->status)->replace('_', ' ')->title()" /></td>
                    <td class="nowrap cell-muted">{{ $issue->reported_at?->format('d M Y H:i') }}</td>
                    @if ($canManage)
                        <td>
                            <select class="form-input form-input-sm" wire:change="updateStatus({{ $issue->id }}, $event.target.value)" aria-label="Status for {{ $issue->vehicle?->number_plate }} issue">
                                @foreach ($statuses as $option)
                                    <option value="{{ $option }}" @selected($issue->status === $option)>{{ str($option)->replace('_', ' ')->title() }}</option>
                                @endforeach
                            </select>
                        </td>
                    @endif
                </tr>
            @empty
                <x-ui.empty-row :colspan="$canManage ? 7 : 6" icon="triangle-alert" title="No issues found." />
            @endforelse

            @if ($issues->hasPages())
                <x-slot:footer>
                    <div class="pager-end">{{ $issues->links() }}</div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
