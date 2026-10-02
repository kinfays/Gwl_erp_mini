{{-- Dismissible chips for the dashboard drill-down filters (age, warranty, unassigned) on the Assets, Phones and Network lists. --}}
@props(['chips' => []])

@if (count($chips))
    <div class="ui-tags" role="status" aria-label="Active dashboard filters" style="padding: 0.5rem 1rem;">
        <span class="ui-hint">Filtered by:</span>
        @foreach ($chips as $key => $label)
            <button type="button" wire:click="clearAnalyticsFilter('{{ $key }}')" class="btn btn-ghost btn-sm" aria-label="Remove filter: {{ $label }}">
                {{ $label }}
                <x-ui.icon name="x" class="icon-sm" />
            </button>
        @endforeach
    </div>
@endif
