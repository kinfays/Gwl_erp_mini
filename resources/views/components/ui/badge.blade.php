{{--
    Small count or tag chip (not a status — use <x-ui.status-pill> for states).
    Tones: neutral (default) · primary · success · warning · danger · lagoon.
    Docs: docs/11-ui-components.md#badge
--}}
@props([
    'tone' => 'neutral',
])

<span {{ $attributes->class(['ui-badge', 'ui-badge-'.$tone]) }}>{{ $slot }}</span>
