{{-- The date-range control of the Billing screen (Phase 8a), reused by Summary and Home (Phase 8f): a whole-month shortcut and two typed dates. Expects $monthPresets, $dateFrom, $dateTo. --}}
<div class="ui-toolbar dash-row" role="search" aria-label="Date range" data-range-control>
    <select wire:model.live="preset" class="form-input" aria-label="A whole month">
        <option value="">A whole month…</option>
        @foreach ($monthPresets as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
    </select>
    <input type="text" wire:model.live.debounce.500ms="dateFrom" class="form-input" style="max-width:11rem" placeholder="From date 2026-07-15" aria-label="From date (YYYY-MM-DD)" autocomplete="off">
    <input type="text" wire:model.live.debounce.500ms="dateTo" class="form-input" style="max-width:11rem" placeholder="To date 2026-08-31" aria-label="To date (YYYY-MM-DD)" autocomplete="off">
    @if ($dateFrom !== '' || $dateTo !== '')<button type="button" wire:click="clearDates" class="btn btn-ghost btn-sm">Clear dates</button>@endif
</div>
