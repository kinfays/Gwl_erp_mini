<div>
    <x-ui.page-header title="Safety actions" description="Corrective and preventive actions raised on incidents.">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button :href="route('health_safety.export', ['report' => 'actions'] + $exportFilters)" icon="file-spreadsheet">Excel</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padded="false" class="dash-row">
        <div class="ui-toolbar" role="search" aria-label="Filter actions">
            <select class="form-input" wire:model.live="filter" aria-label="Show">
                <option value="">All actions</option>
                <option value="open">Open</option>
                <option value="overdue">Overdue</option>
                <option value="done">Done, not yet verified</option>
                <option value="verified">Verified</option>
            </select>
            <x-ui.checkbox label="Assigned to me" wire:model.live="mine" />
        </div>

        <x-ui.table label="Safety actions">
            <x-slot:head>
                <tr>
                    <th>Incident</th>
                    <th>Action</th>
                    <th>Assigned to</th>
                    <th>Due</th>
                    <th>Status</th>
                    <th class="actions"><span class="sr-only-text">Update</span></th>
                </tr>
            </x-slot:head>
            @forelse ($actions as $action)
                @php
                    $own = $employeeId && (int) $action->assigned_to_employee_id === (int) $employeeId;
                    $manages = $visibility->canManage($viewer, $action->incident);
                @endphp
                <tr wire:key="hs-action-{{ $action->id }}">
                    <td>
                        @if ($visibility->canView($viewer, $action->incident))
                            <a class="mono" href="{{ route('health_safety.incidents.show', $action->incident) }}">{{ $action->incident->reference }}</a>
                        @else
                            <span class="mono">{{ $action->incident->reference }}</span>
                        @endif
                    </td>
                    <td>
                        {{ $action->description }}
                        @if ($action->completion_note)<br><span class="ui-person-sub">{{ $action->completion_note }}</span>@endif
                        @if ($action->status === \App\Models\HsIncidentAction::STATUS_OPEN && ($own || $manages))
                            <input type="text" class="form-input form-input-sm" style="margin-top:6px" wire:model="notes.{{ $action->id }}" placeholder="What was done (optional)" aria-label="Completion note for this action" maxlength="1000">
                        @endif
                    </td>
                    <td>{{ $action->assignee?->full_name }}</td>
                    <td class="nowrap {{ $action->isOverdue() ? '' : 'cell-muted' }}">
                        {{ $action->due_on->format('d M Y') }}@if ($action->isOverdue()) <x-ui.badge tone="danger">Overdue</x-ui.badge>@endif
                    </td>
                    <td><x-ui.status-pill domain="hs-action" :status="$action->status" /></td>
                    <td class="actions">
                        @if ($action->status === \App\Models\HsIncidentAction::STATUS_OPEN && ($own || $manages))
                            <x-ui.button size="sm" wire:click="complete({{ $action->id }})" loading="complete({{ $action->id }})">Mark done</x-ui.button>
                        @elseif ($action->status === \App\Models\HsIncidentAction::STATUS_DONE && $manages)
                            <x-ui.button size="sm" wire:click="verify({{ $action->id }})" loading="verify({{ $action->id }})">Verify</x-ui.button>
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="6" icon="list-checks" title="No actions to show." />
            @endforelse

            @if ($actions->hasPages())
                <x-slot:footer>
                    <div class="pager-end">{{ $actions->links() }}</div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
