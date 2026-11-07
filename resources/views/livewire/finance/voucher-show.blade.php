<div>
    <x-ui.page-header title="Payment voucher" description="The voucher, what Audit and Finance have done with it, and what happens next.">
        <x-slot:actions>
            <x-ui.button icon="arrow-left" :href="route('finance.home')">All vouchers</x-ui.button>
            @if ($canEdit)<x-ui.button icon="pencil" :href="route('finance.pvs.edit', $pv->id)">Edit</x-ui.button>@endif
            @if ($canVoid)<x-ui.button variant="danger" wire:click="openVoid">Void</x-ui.button>@endif
            @if ($canAcknowledge)<x-ui.button variant="primary" icon="check" wire:click="acknowledge" loading="acknowledge">Acknowledge</x-ui.button>@endif
            @if ($canSubmit)<x-ui.button variant="primary" icon="send" wire:click="submit" loading="submit">Send to Audit</x-ui.button>@endif
            @if ($canResubmit)<x-ui.button variant="primary" icon="send" wire:click="resubmit" loading="resubmit">Resubmit to Audit</x-ui.button>@endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))<x-ui.alert tone="success" role="status" class="dash-row">{{ session('status') }}</x-ui.alert>@endif
    @if (session('error'))<x-ui.alert tone="danger" role="alert" class="dash-row">{{ session('error') }}</x-ui.alert>@endif
    @foreach (['action.status', 'action.action', 'action.reason', 'conflict'] as $key)
        @error($key)<x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    @if ($pv->status === \App\Models\FinPaymentVoucher::QUERIED)
        @php($query = $pv->events->last(fn ($event) => $event->action === \App\Models\FinPvEvent::QUERIED))
        <x-ui.alert tone="danger" title="Audit queried this voucher" class="dash-row">
            <strong>{{ $query?->reasonLabel() }}.</strong> {{ $query?->comment }}
            <div class="cell-muted">{{ $query?->actorName() }}, {{ $query?->created_at?->format('d M Y H:i') }}</div>
            @if ($canResubmit)
                <p>Edit the voucher to correct it, then resubmit.</p>
            @endif
        </x-ui.alert>
    @endif

    @include('livewire.finance.partials.pv-detail')

    @if ($showVoid)
        <x-ui.modal title="Void this voucher" description="The voucher stays on record, marked voided, with your reason. Its number can never be used again." close="$set('showVoid', false)">
            <x-ui.textarea label="Reason" wire:model.defer="voidReason" rows="3" maxlength="500" />
            @error('void.reason') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="$set('showVoid', false)">Cancel</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="confirmVoid">Void voucher</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
