@props(['value'])

<label {{ $attributes->merge(['class' => 'ui-label']) }}>
    {{ $value ?? $slot }}
</label>
