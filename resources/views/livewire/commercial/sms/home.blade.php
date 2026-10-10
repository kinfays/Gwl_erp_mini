<div>
    <x-ui.page-header title="SMS to customers" description="Message the customers of {{ implode(', ', $regionNames) }} by SMS: all of them, hand-picked ones, or a group. Each campaign has a delivery report." />

    @unless ($configured)
        <x-ui.alert tone="warning" class="dash-row">The SMS provider key is not set on this server (GWL_ARKESEL_API_KEY), so nothing can be sent and the balance cannot be read.</x-ui.alert>
    @endunless

    <x-ui.card title="What do you want to do?" class="dash-row">
        <div class="ui-toolbar">
            @if ($canSend)<a href="{{ route('commercial.sms.send') }}" class="btn btn-primary">Send SMS</a>@endif
            @if ($canSend || $canSeeReports)<a href="{{ route('commercial.sms.campaigns') }}" class="btn btn-secondary">Campaigns and delivery reports</a>@endif
            @if ($canSend)<a href="{{ route('commercial.sms.templates') }}" class="btn btn-secondary">Templates</a>@endif
            @if ($canManage)<a href="{{ route('commercial.sms.settings') }}" class="btn btn-secondary">Settings</a>@endif
        </div>
    </x-ui.card>

    @if ($canSeeBalance && $balance)
        @if ($balance['low'])
            <x-ui.alert tone="warning" class="dash-row">SMS credits are running low: about {{ number_format($balance['credits']) }} left (warning level {{ number_format((int) config('gwl.commercial_sms_low_balance')) }}). Ask for a top-up before the next campaign.</x-ui.alert>
        @endif

        <x-ui.card title="SMS balance" description="Read from the provider and kept for a few minutes." class="dash-row">
            @if ($balance['available'])
                <p><strong>{{ number_format($balance['credits']) }}</strong> credits</p>
                <p class="ui-hint">As of {{ \Illuminate\Support\Carbon::parse($balance['at'])->diffForHumans() }}. A campaign's cost is an estimate: recipients x message parts.</p>
            @else
                <p><strong>Unavailable</strong></p>
                <p class="ui-hint">The provider could not be reached or its answer could not be read ({{ $balance['reason'] }}). This is not a zero balance. Sending is blocked until the balance can be read.</p>
            @endif
            <button type="button" class="btn btn-secondary btn-sm" wire:click="refreshBalance" wire:loading.attr="disabled">Refresh</button>
            @if ($refreshed)<span class="ui-hint">Refreshed (the balance is re-read at most every 30 seconds).</span>@endif
        </x-ui.card>
    @endif
</div>
