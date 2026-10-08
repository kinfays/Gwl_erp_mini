<div>
    <x-ui.page-header title="My reports" description="Everything you have reported, and what happened to it.">
        <x-slot:actions>
            <x-ui.button :href="route('health_safety.report')" variant="primary" icon="circle-plus">Report an incident</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padded="false" class="dash-row">
        <x-ui.table label="My incident reports">
            <x-slot:head>
                <tr>
                    <th>Reference</th>
                    <th>What</th>
                    <th>Where</th>
                    <th>Occurred</th>
                    <th>Status</th>
                    <th>Outcome</th>
                </tr>
            </x-slot:head>
            @forelse ($reports as $report)
                <tr wire:key="hs-mine-{{ $report->id }}">
                    <td><a class="mono" href="{{ route('health_safety.incidents.show', $report) }}">{{ $report->reference }}</a></td>
                    <td>{{ $report->typeLabel() }}</td>
                    <td>{{ $report->placeLabel() }}</td>
                    <td class="nowrap cell-muted">{{ $report->occurred_on->format('d M Y') }}</td>
                    <td><x-ui.status-pill domain="hs-incident" :status="$report->status" /></td>
                    <td>
                        {{-- The closure note is feedback for the reporter, shown only once the incident is closed. --}}
                        @if ($visibility->canSeeClosureNote($viewer, $report) && $report->status === \App\Models\HsIncident::STATUS_CLOSED && filled($report->closure_note))
                            {{ \Illuminate\Support\Str::limit($report->closure_note, 140) }}
                        @else
                            <span class="cell-muted">&mdash;</span>
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="6" icon="clipboard-list" title="You have not reported anything yet.">
                    <x-ui.button size="sm" :href="route('health_safety.report')">Report an incident</x-ui.button>
                </x-ui.empty-row>
            @endforelse

            @if ($reports->hasPages())
                <x-slot:footer>
                    <div class="pager-end">{{ $reports->links() }}</div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
