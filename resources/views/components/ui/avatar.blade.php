{{--
    Initials avatar. The tint is derived from the name, so a person keeps the same colour on every
    page. Decorative (aria-hidden) — show the name next to it. Docs: docs/11-ui-components.md#avatar
--}}
@props([
    'name' => '',
    'initials' => null,
    'tone' => null,
    'size' => 'md',
])

@php
    $initials = $initials ?: collect(preg_split('/\s+/', trim((string) $name)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->join('');
    $tones = ['primary', 'lagoon', 'muted', 'warm'];
    $tone = $tone ?: $tones[crc32(mb_strtolower(trim((string) $name))) % count($tones)];
@endphp

<span {{ $attributes->class(['ui-avatar', 'ui-avatar-'.$size, 'ui-avatar-'.$tone]) }} aria-hidden="true">{{ $initials ?: '?' }}</span>
