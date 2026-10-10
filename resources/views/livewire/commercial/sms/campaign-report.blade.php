<div @if ($campaign->isOpen()) wire:poll.10s @endif>
    <x-ui.page-header title="SMS campaign #{{ $campaign->id }}{{ $campaign->name ? ' · '.$campaign->name : '' }}" description="{{ $audienceText }}">
        <x-slot:actions>
            @if ($canExport && ! $summary['purged'])<a href="{{ route('commercial.sms.campaigns.export', $campaign->id) }}" class="btn btn-secondary"><x-ui.icon name="file-spreadsheet" /> Export to Excel</a>@endif
            @if ($canSend && $campaign->isOpen() && $campaign->status !== 'cancelling')<button type="button" class="btn btn-ghost" wire:click="cancel" wire:confirm="Cancel this campaign? Messages already sent cannot be taken back.">Cancel</button>@endif
            <a href="{{ route('commercial.sms.campaigns') }}" class="btn btn-secondary">All campaigns</a>
        </x-slot:actions>
    </x-ui.page-header>

    <p class="dash-row">
        <x-ui.badge :tone="match ($campaign->status) { 'completed' => 'success', 'failed', 'blocked' => 'danger', 'running', 'cancelling', 'queued' => 'info', default => 'neutral' }">{{ ucfirst($campaign->status) }}</x-ui.badge>
        Sender <strong class="mono">{{ $campaign->sender_id }}</strong> · {{ $campaign->segments }} part(s) each · filed by {{ $campaign->creator?->full_name ?? 'someone who has since left' }} on {{ $campaign->created_at->format('d M Y H:i') }}
    </p>

    @if ($campaign->error_message)<x-ui.alert tone="danger" class="dash-row">{{ $campaign->error_message }}</x-ui.alert>@endif
    @if (! $summary['delivery_reports'])<x-ui.alert tone="info" class="dash-row">This report shows what the provider accepted only: per-message delivery reports are switched off.</x-ui.alert>@endif
    @if ($summary['purged'])<x-ui.alert tone="info" class="dash-row">The who-got-what rows of this campaign were deleted under the retention setting ({{ $campaign->details_purged_at->format('d M Y') }}). The totals below are kept.</x-ui.alert>@endif

    <x-ui.card title="Totals" description="Percentages are of the audience." :padded="false" class="dash-row">
        <x-ui.table label="Campaign totals" :sticky="false">
            <x-slot:head><tr><th>What</th><th class="num">Messages</th><th class="num">Share</th></tr></x-slot:head>
            @foreach ($summary['rows'] as $row)
                <tr wire:key="t-{{ $row['key'] }}"><td>{{ $row['label'] }}</td><td class="num">{{ number_format($row['count']) }}</td><td class="num">{{ $row['pct'] === null ? '–' : number_format($row['pct'], 1).'%' }}</td></tr>
            @endforeach
        </x-ui.table>
        <p class="ui-hint dash-row">
            Credits used {{ number_format($summary['credits_used']) }} (estimated {{ number_format($summary['credits_estimated']) }}).
            @if ($summary['sharing'] > 0) {{ number_format($summary['sharing']) }} accounts shared a number with another account, so the audience is smaller than the accounts. @endif
        </p>
    </x-ui.card>

    <x-ui.card title="Timeline" class="dash-row">
        <ul>
            @foreach ($summary['timeline'] as $event)<li>{{ $event['at']->format('d M Y H:i') }} · {{ $event['label'] }}</li>@endforeach
        </ul>
    </x-ui.card>

    @if ($summary['failures'] !== [])
        <x-ui.card title="Why messages failed or are unknown" :padded="false" class="dash-row">
            <x-ui.table label="Failures by reason" :sticky="false">
                <x-slot:head><tr><th>Reason</th><th class="num">Messages</th></tr></x-slot:head>
                @foreach ($summary['failures'] as $failure)<tr wire:key="f-{{ $failure['code'] }}"><td>{{ $failure['label'] }}</td><td class="num">{{ number_format($failure['count']) }}</td></tr>@endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($page)
        <x-ui.card title="Recipients" description="Numbers are masked." :padded="false" class="dash-row">
            <div class="ui-toolbar">
                <select wire:model.live="status" class="form-input" aria-label="Status filter">
                    <option value="">Every status</option>
                    @foreach ($statuses as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </div>
            <x-ui.table label="Recipients" :sticky="true">
                <x-slot:head><tr><th>Account</th>@if ($details)<th>Name</th><th>Mobile</th>@endif<th>District</th><th>Status</th><th>Reason</th><th>Accepted</th>@if ($summary['delivery_reports'])<th>Delivered</th>@endif</tr></x-slot:head>
                @forelse ($page['rows'] as $row)
                    <tr wire:key="r-{{ $row['id'] }}">
                        <td class="mono">{{ $row['account_no'] }}</td>
                        @if ($details)<td>{{ $row['name'] ?? '–' }}</td><td class="mono">{{ $row['mobile'] ?? '–' }}</td>@endif
                        <td>{{ $row['district'] }}</td><td>{{ $row['status_label'] }}</td><td>{{ $row['reason'] }}</td>
                        <td>{{ $row['submitted_at'] ? \Illuminate\Support\Carbon::parse($row['submitted_at'])->format('d M H:i') : '–' }}</td>
                        @if ($summary['delivery_reports'])<td>{{ $row['delivered_at'] ? \Illuminate\Support\Carbon::parse($row['delivered_at'])->format('d M H:i') : '–' }}</td>@endif
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="$details ? 8 : 6" icon="message-square" title="No recipients match." />
                @endforelse
            </x-ui.table>
            <div class="ui-toolbar">
                @if ($after !== null)<button type="button" class="btn btn-ghost btn-sm" wire:click="previousPage">Previous</button>@endif
                @if ($page['next'])<button type="button" class="btn btn-ghost btn-sm" wire:click="nextPage({{ $page['next'] }})">Next</button>@endif
            </div>
        </x-ui.card>
    @endif
</div>
