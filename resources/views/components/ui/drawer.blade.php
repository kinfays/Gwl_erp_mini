{{--
    Side panel that slides in from the right. `show` and `close` are Alpine expressions evaluated in
    the caller's x-data scope, e.g. <x-ui.drawer show="isOpen" close="close()" title="User Profile">.
    For a Livewire-rendered panel inside @if, use show="true" and a Livewire close via $wire.
    Focus is trapped while open; Escape and the backdrop close it.
    Docs: docs/11-ui-components.md#modal-and-drawer
--}}
@props([
    'title',
    'description' => null,
    'show' => 'open',
    'close' => 'open = false',
    'width' => '36rem',
])

@php
    $drawerId = 'drw-'.\Illuminate\Support\Str::slug($title);
@endphp

<div class="ui-drawer-root" x-data x-show="{{ $show }}" x-cloak x-on:keydown.escape.window="if ({{ $show }}) { {{ $close }} }">
    <div class="ui-drawer-backdrop" x-show="{{ $show }}" x-transition.opacity x-on:click="{{ $close }}" aria-hidden="true"></div>

    <aside
        {{ $attributes->class(['ui-drawer']) }}
        style="--drawer-width: {{ $width }}"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $drawerId }}-title"
        x-show="{{ $show }}"
        x-trap.inert.noscroll="{{ $show }}"
        x-transition:enter="ui-drawer-enter"
        x-transition:enter-start="ui-drawer-out"
        x-transition:enter-end="ui-drawer-in"
        x-transition:leave="ui-drawer-leave"
        x-transition:leave-start="ui-drawer-in"
        x-transition:leave-end="ui-drawer-out"
    >
        <header class="ui-drawer-head">
            <div class="ui-drawer-titles">
                <h2 id="{{ $drawerId }}-title" class="ui-drawer-title">{{ $title }}</h2>
                @if ($description)
                    <p class="ui-drawer-desc">{{ $description }}</p>
                @endif
            </div>
            <button type="button" class="icon-btn" x-on:click="{{ $close }}" aria-label="{{ __('Close panel') }}">
                <x-ui.icon name="x" />
            </button>
        </header>

        <div class="ui-drawer-body">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="ui-drawer-foot">{{ $footer }}</footer>
        @endisset
    </aside>
</div>
