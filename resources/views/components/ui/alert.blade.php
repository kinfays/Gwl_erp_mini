{{--
    Inline message. Tones: info · success · warning · danger. Add role="alert" yourself only for
    messages that appear in response to an action. An `actions` slot sits at the end of the row
    (e.g. a dismiss button). Docs: docs/11-ui-components.md#alert
--}}
@props([
    'tone' => 'info',
    'title' => null,
    'icon' => null,
])

@php
    $icon = $icon ?: match ($tone) {
        'success' => 'circle-check',
        'warning' => 'triangle-alert',
        'danger' => 'circle-alert',
        default => 'info',
    };
@endphp

<div {{ $attributes->class(['alert', 'alert-'.$tone]) }}>
    <x-ui.icon :name="$icon" class="alert-icon" />
    <div class="alert-body">
        @if ($title)
            <strong class="alert-title">{{ $title }}</strong>
        @endif
        {{ $slot }}
    </div>

    @isset($actions)
        <div class="alert-actions">{{ $actions }}</div>
    @endisset
</div>
