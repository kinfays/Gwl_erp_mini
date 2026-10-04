{{-- Read-only, newest-first change history of the asset being edited (ict_asset_transfers). --}}
@props(['transfers', 'editing' => false])

@if ($editing)
    <div class="span-2">
        <span class="ui-label">History</span>
        @forelse ($transfers as $transfer)
            @if ($loop->first)
                <ul class="ui-hint" aria-label="Asset history" style="list-style: none; padding: 0; margin: 0.25rem 0 0;">
            @endif
                <li wire:key="transfer-{{ $transfer->id }}">{{ $transfer->summary() }} &mdash; {{ $transfer->occurred_at?->format('j M Y') }}</li>
            @if ($loop->last)
                </ul>
            @endif
        @empty
            <p class="ui-hint">No changes recorded yet.</p>
        @endforelse
    </div>
@endif
