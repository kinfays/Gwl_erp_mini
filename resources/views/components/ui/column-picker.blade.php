{{--
    The three-dot menu in a list's "Action" header, for a list that uses App\Livewire\Concerns\HasSelectableColumns:
    Default Columns / All Columns / Customize Columns, and below them a checklist that is live in Customize mode.
        <x-ui.column-picker :columns="$columnManifest" :visible="$visibleColumns" :mode="$columnMode" />
    The panel is positioned from the button (fixed), because the table scrolls and would clip a normal dropdown.
    Locked columns are shown ticked and cannot be turned off.
--}}
@props(['columns' => [], 'visible' => [], 'mode' => 'default'])

<div
    class="ui-column-picker"
    x-data="{
        open: false,
        mode: @js($mode),
        top: 0,
        right: 0,
        toggle() {
            this.open = ! this.open;
            if (this.open) {
                const rect = this.$refs.button.getBoundingClientRect();
                this.top = rect.bottom + 6;
                this.right = Math.max(8, window.innerWidth - rect.right);
            }
        },
    }"
    x-on:click.outside="open = false"
    x-on:keydown.escape="open = false"
    x-on:scroll.window="open = false"
>
    <button type="button" x-ref="button" class="btn btn-ghost btn-sm btn-icon" x-on:click="toggle()" x-bind:aria-expanded="open.toString()" aria-haspopup="true" title="Choose columns" aria-label="Choose columns">
        <x-ui.icon name="ellipsis-vertical" />
    </button>

    <div class="ui-column-panel" x-show="open" x-cloak x-bind:style="`top: ${top}px; right: ${right}px`" role="group" aria-label="Choose columns">
        <label class="ui-column-option ui-column-mode">
            <input type="radio" value="default" x-model="mode" wire:click="resetColumns">
            <span>Default Columns</span>
        </label>
        <label class="ui-column-option ui-column-mode">
            <input type="radio" value="all" x-model="mode" wire:click="showAllColumns">
            <span>All Columns</span>
        </label>
        <label class="ui-column-option ui-column-mode">
            <input type="radio" value="custom" x-model="mode">
            <span>Customize Columns</span>
        </label>

        <hr class="ui-column-divider">

        @foreach ($columns as $key => $column)
            @php $locked = (bool) ($column['locked'] ?? false); @endphp
            <label class="ui-column-option {{ $locked ? 'is-locked' : '' }}" x-bind:class="{ 'is-inactive': mode !== 'custom' }" wire:key="column-option-{{ $key }}">
                <input
                    type="checkbox"
                    @checked(in_array($key, $visible, true))
                    @if ($locked)
                        disabled
                    @else
                        x-bind:disabled="mode !== 'custom'"
                        wire:click="toggleColumn('{{ $key }}')"
                    @endif
                >
                <span>{{ $column['label'] }}</span>
            </label>
        @endforeach
    </div>
</div>
