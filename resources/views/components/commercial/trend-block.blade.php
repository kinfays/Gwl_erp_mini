{{-- The "change on the previous comparable period" block of a screen that already shows the figure (Phase 8f, T2): the same data as the Trends screen's part (TrendReport::block), so the
     two cannot disagree. `block` is null when the viewer may not have it (nothing is drawn). A ratio changes in percentage points; the previous period is an upload of about the same length
     (never a 7-day upload against a 31-day one). --}}
@props(['block', 'kind', 'query' => []])

@if ($block)
    <x-ui.card title="Change on the previous comparable period" class="dash-row" data-trend-block="{{ $kind }}">
        <x-slot:actions><a href="{{ route('commercial.trends.show', ['trend' => 't2', 'kind' => $kind, ...$query]) }}" class="btn btn-ghost btn-sm">All trends</a></x-slot:actions>
        @if ($block['available'])
            @include('livewire.commercial.trends.pair', ['p' => $block, 'vs' => 'Previous period'])
        @else
            <p class="ui-hint" data-empty-part>{{ $block['note'] }}</p>
        @endif
    </x-ui.card>
@endif
