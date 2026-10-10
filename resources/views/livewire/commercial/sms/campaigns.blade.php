<div>
    <x-ui.page-header title="SMS campaigns" description="What has been sent, is running or is scheduled for your region.">
        <x-slot:actions>
            @if ($canSend)<a href="{{ route('commercial.sms.send') }}" class="btn btn-primary">Send SMS</a>@endif
            <a href="{{ route('commercial.sms') }}" class="btn btn-secondary">Back</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-toolbar dash-row">
        <select wire:model.live="status" class="form-input" aria-label="Status">
            <option value="">Every status</option>
            @foreach (['scheduled' => 'Scheduled', 'queued' => 'Waiting to start', 'running' => 'Running', 'cancelling' => 'Cancelling', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'failed' => 'Stopped (failed)', 'blocked' => 'Blocked'] as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <x-ui.card :padded="false" class="dash-row">
        <x-ui.table label="SMS campaigns" :sticky="false">
            <x-slot:head><tr><th>Campaign</th><th>Status</th><th>Sender</th><th class="num">Recipients</th><th class="num">Credits</th><th>Filed</th><th></th></tr></x-slot:head>
            @forelse ($campaigns as $campaign)
                <tr wire:key="c-{{ $campaign->id }}">
                    <td>
                        @if ($canSeeReports)<a href="{{ route('commercial.sms.campaigns.show', $campaign->id) }}">#{{ $campaign->id }}{{ $campaign->name ? ' · '.$campaign->name : '' }}</a>@else #{{ $campaign->id }}{{ $campaign->name ? ' · '.$campaign->name : '' }}@endif
                        <div class="ui-hint">{{ ucfirst($campaign->audience_type) }}{{ $campaign->audience_type === 'group' ? ': '.(\App\Services\Commercial\Sms\SmsAudienceResolver::GROUPS[$campaign->audience['group'] ?? ''] ?? '') : '' }}</div>
                    </td>
                    <td>
                        <x-ui.badge :tone="match ($campaign->status) { 'completed' => 'success', 'failed', 'blocked' => 'danger', 'running', 'cancelling', 'queued' => 'info', default => 'neutral' }">{{ ucfirst($campaign->status) }}</x-ui.badge>
                        @if ($campaign->status === 'scheduled' && $campaign->scheduled_at)<div class="ui-hint">{{ $campaign->scheduled_at->format('d M Y H:i') }}</div>@endif
                    </td>
                    <td class="mono">{{ $campaign->sender_id }}</td>
                    <td class="num">{{ number_format($campaign->recipients_total) }}</td>
                    <td class="num">{{ number_format($campaign->credits_used) }} <span class="ui-hint">of ~{{ number_format($campaign->credits_estimated) }}</span></td>
                    <td>{{ $campaign->created_at->format('d M Y H:i') }}<div class="ui-hint">{{ $campaign->creator?->full_name }}</div></td>
                    <td>@if ($canSend && $campaign->isOpen() && $campaign->status !== 'cancelling')<button type="button" class="btn btn-ghost btn-sm" wire:click="cancel({{ $campaign->id }})" wire:confirm="Cancel this campaign? Messages already sent cannot be taken back.">Cancel</button>@endif</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="message-square" title="No campaigns yet." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <div class="dash-row">{{ $campaigns->links() }}</div>
</div>
