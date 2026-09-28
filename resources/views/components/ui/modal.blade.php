{{--
    Dialog for Livewire-controlled modals, rendered inside the caller's @if ($showX) block.
    `close` is the Livewire action that hides it, e.g. close="$set('showCreateRole', false)"; it runs
    from the × button, the backdrop and Escape. Focus moves into the dialog and is trapped there.
    On phones it becomes a bottom sheet. Docs: docs/11-ui-components.md#modal-and-drawer
--}}
@props([
    'title',
    'description' => null,
    'size' => 'md',
    'close' => null,
    'icon' => null,
    'tone' => 'primary',
])

@php
    $dialogId = 'dlg-'.\Illuminate\Support\Str::slug($title);
@endphp

<div
    class="ui-modal-backdrop"
    @if ($close) wire:click.self="{{ $close }}" @endif
>
    <div
        {{ $attributes->class(['ui-modal', 'ui-modal-'.$size]) }}
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $dialogId }}-title"
        @if ($description) aria-describedby="{{ $dialogId }}-desc" @endif
        x-data
        x-trap.inert.noscroll="true"
        @if ($close) x-on:keydown.escape.prevent.stop="$wire.{{ $close }}" @endif
    >
        <header class="ui-modal-head">
            @if ($icon)
                <span class="ui-chip ui-chip-{{ $tone }}" aria-hidden="true"><x-ui.icon :name="$icon" /></span>
            @endif
            <div class="ui-modal-titles">
                <h2 id="{{ $dialogId }}-title" class="ui-modal-title">{{ $title }}</h2>
                @if ($description)
                    <p id="{{ $dialogId }}-desc" class="ui-modal-desc">{{ $description }}</p>
                @endif
            </div>
        </header>

        <div class="ui-modal-body">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="ui-modal-foot">{{ $footer }}</footer>
        @endisset

        {{-- Last in the tab order so focus starts on the first field; pinned top-right by CSS. --}}
        @if ($close)
            <button type="button" class="icon-btn ui-modal-close" wire:click="{{ $close }}" aria-label="{{ __('Close dialog') }}">
                <x-ui.icon name="x" />
            </button>
        @endif
    </div>
</div>
