@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'alert alert-success', 'role' => 'status']) }}>
        <x-ui.icon name="circle-check" class="alert-icon" />
        <div class="alert-body">{{ $status }}</div>
    </div>
@endif
