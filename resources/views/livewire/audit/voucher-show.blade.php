<div>
    <x-ui.page-header title="Voucher for review" description="Check the voucher and its documents, then acknowledge it (tick) or query it (X) with a reason.">
        <x-slot:actions>
            <x-ui.button icon="arrow-left" :href="route('audit.home')">Review queue</x-ui.button>
            @if ($canWithdraw)<x-ui.button variant="warn" wire:click="openWithdraw">Withdraw tick</x-ui.button>@endif
            @if ($canQuery)<x-ui.button variant="danger" icon="x" wire:click="openQuery">Query</x-ui.button>@endif
            @if ($canTick)<x-ui.button variant="primary" icon="check" wire:click="tick" loading="tick">Acknowledge</x-ui.button>@endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($submittedByMe)
        <x-ui.alert tone="info" role="status" class="dash-row">You sent this voucher to Audit, so you cannot review it. Another Audit officer has to.</x-ui.alert>
    @endif
    @foreach (['action.status', 'action.action'] as $key)
        @error($key)<x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    @include('livewire.finance.partials.pv-detail')

    @if ($showQuery)
        <x-ui.modal title="Query this voucher" description="Finance is told and can correct and resubmit it. Choose the main reason and say exactly what is wrong." close="$set('showQuery', false)">
            <x-ui.select label="Reason" required wire:model="reasonCode">
                <option value="">Choose a reason</option>
                @foreach ($reasons as $code => $label)
                    <option value="{{ $code }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
            @error('query.reason_code') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-ui.textarea label="Comment" required wire:model.defer="comment" rows="4" maxlength="1000" />
            @error('query.comment') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            @error('query.status') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            @error('query.action') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="$set('showQuery', false)">Cancel</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="confirmQuery">Query voucher</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($showWithdraw)
        <x-ui.modal title="Withdraw your tick" description="The voucher goes back to With Audit and Finance is told. Possible only until Finance acknowledges it." close="$set('showWithdraw', false)">
            <x-ui.textarea label="Reason" required wire:model.defer="withdrawReason" rows="3" maxlength="1000" />
            @error('withdraw.reason') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            @error('withdraw.status') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            @error('withdraw.action') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="$set('showWithdraw', false)">Cancel</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="confirmWithdraw">Withdraw tick</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
