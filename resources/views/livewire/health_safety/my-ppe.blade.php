<div>
    <x-ui.page-header title="My PPE" description="The protective equipment you have been given, and when it should be replaced." />

    @php $needsAttention = $gaps->filter(fn ($row) => $row['state'] !== 'ok'); @endphp

    @if ($needsAttention->isNotEmpty())
        <x-ui.alert tone="warning" title="Ask your Health & Safety Officer about:" class="dash-row">
            <ul>
                @foreach ($needsAttention as $row)
                    <li wire:key="hs-my-gap-{{ $row['ppe_type_id'] }}">
                        {{ $row['type'] }}: {{ strtolower($states[$row['state']]) }}
                        (you should have {{ $row['entitled'] }}, you hold {{ $row['held'] }}@if ($row['next_due']), next due {{ $row['next_due']->format('d M Y') }}@endif)
                    </li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <x-ui.card title="What I hold" :padded="false" class="dash-row">
        <x-ui.table label="PPE I hold">
            <x-slot:head><tr><th>PPE</th><th class="num">Qty</th><th>Given</th><th>Replace by</th><th>State</th><th class="actions"><span class="sr-only-text">Confirm</span></th></tr></x-slot:head>
            @forelse ($open as $issue)
                <tr wire:key="hs-my-ppe-{{ $issue->id }}">
                    <td>{{ $issue->type?->name }}@if ($issue->size) <span class="cell-muted">(size {{ $issue->size }})</span>@endif</td>
                    <td class="num">{{ $issue->quantity }}</td>
                    <td class="nowrap">{{ $issue->issued_on->format('d M Y') }}</td>
                    <td class="nowrap">{{ $issue->replace_due_on?->format('d M Y') ?? '—' }}</td>
                    <td><x-ui.status-pill domain="hs-ppe-issue" :status="$issue->state()" /></td>
                    <td class="actions">
                        @if ($issue->acknowledged_at)
                            <x-ui.badge tone="success">Receipt confirmed</x-ui.badge>
                        @else
                            <x-ui.button size="sm" variant="primary" wire:click="confirm({{ $issue->id }})" loading="confirm({{ $issue->id }})">Confirm I received this</x-ui.button>
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="6" icon="boxes" title="No PPE is recorded against you." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    @if ($closed->isNotEmpty())
        <x-ui.card title="Earlier" description="Your most recent returned, worn-out, damaged or lost items." :padded="false" class="dash-row">
            <x-ui.table label="PPE I no longer hold">
                <x-slot:head><tr><th>PPE</th><th class="num">Qty</th><th>Given</th><th>Ended</th><th>How</th></tr></x-slot:head>
                @foreach ($closed as $issue)
                    <tr wire:key="hs-my-ppe-old-{{ $issue->id }}">
                        <td>{{ $issue->type?->name }}@if ($issue->size) <span class="cell-muted">(size {{ $issue->size }})</span>@endif</td>
                        <td class="num">{{ $issue->quantity }}</td>
                        <td class="nowrap">{{ $issue->issued_on->format('d M Y') }}</td>
                        <td class="nowrap">{{ $issue->closed_on?->format('d M Y') }}</td>
                        <td><x-ui.status-pill domain="hs-ppe-issue" :status="$issue->status" /></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
