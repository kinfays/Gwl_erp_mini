{{-- One block of an "Across the files" page (Phase 8e). `part` is what CrossFileReport made: title, visible, empty, reason, data. A block the viewer may not have is a line saying
     what is hidden (the other blocks of the page stay); a block with nothing to show says why; otherwise the slot is drawn. --}}
@props(['part', 'description' => null])

<x-ui.card :title="$part['title']" :description="$description" class="dash-row" data-part="{{ $part['key'] ?? '' }}">
    @if (! $part['visible'])
        <p class="ui-hint" data-hidden-part><x-ui.icon name="eye-off" class="icon-sm" /> {{ $part['reason'] }}</p>
    @elseif ($part['empty'])
        <p class="ui-hint" data-empty-part>{{ $part['reason'] }}</p>
    @else
        @if (! empty($part['reason']))
            <p class="ui-hint">{{ $part['reason'] }}</p>
        @endif
        {{ $slot }}
    @endif
</x-ui.card>
