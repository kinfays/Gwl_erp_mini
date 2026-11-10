{{-- "Print selected": tick vouchers in the Print column, prepare, then open the PDF from the link (a link, so no browser blocks it). --}}
@if (count($printIds) > 0 || $printUrl)
    <x-ui.alert tone="info" role="status" class="dash-row">
        @if ($printUrl)
            The PDF of {{ $printCount }} {{ \Illuminate\Support\Str::plural('voucher', $printCount) }} is ready:
            <a href="{{ $printUrl }}" target="_blank" rel="noopener"><strong>Open the PDF</strong></a>
            <span class="cell-muted">(each voucher on its own page; the link works once)</span>
        @else
            {{ count($printIds) }} {{ \Illuminate\Support\Str::plural('voucher', count($printIds)) }} ticked for printing.
        @endif
        <x-slot:actions>
            @unless ($printUrl)
                <x-ui.button size="sm" variant="primary" icon="printer" wire:click="preparePrint" loading="preparePrint">Print selected</x-ui.button>
            @endunless
            <x-ui.button size="sm" variant="ghost" wire:click="clearPrint">Clear</x-ui.button>
        </x-slot:actions>
    </x-ui.alert>
@endif
@error('print')<x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert>@enderror
