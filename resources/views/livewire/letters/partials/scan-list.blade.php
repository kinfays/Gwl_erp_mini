{{--
    The non-voided scans of the open letter. In: $selectedScans. Optional: $voidableScanIds and $voidingScanId (the Scans
    tab passes them; the read-only preview on the confirm prompt does not). Files open through letters.scans.show only.
--}}
@php
    $voidable = $voidableScanIds ?? [];
@endphp

@forelse ($selectedScans as $scan)
    <article class="remark-card" wire:key="scan-{{ $scan->id }}">
        <header class="remark-card-head">
            <span class="ui-person">
                <x-ui.icon name="paperclip" class="icon-sm" />
                <a href="{{ route('letters.scans.show', $scan) }}" target="_blank" rel="noopener" class="row-link">{{ $scan->original_name }}</a>
            </span>
            <span class="ui-hint nowrap">{{ $scan->created_at?->format('d M Y H:i') }}</span>
        </header>
        <div class="ui-tags">
            <x-ui.badge tone="lagoon">{{ $scan->kindLabel() }}</x-ui.badge>
            <span class="ui-hint">{{ $scan->humanSize() }} · added by {{ $scan->uploadedBy?->full_name }}</span>
        </div>
        @if ($scan->note)
            <p class="ui-hint">{{ $scan->note }}</p>
        @endif

        @if (in_array($scan->id, $voidable, true))
            @if (($voidingScanId ?? null) === $scan->id)
                <div class="ui-stack">
                    <x-ui.input label="Why is this scan being voided?" wire:model="voidReason" maxlength="255" placeholder="e.g. Wrong letter, unreadable" />
                    <div class="ui-form-actions">
                        <button type="button" wire:click="cancelVoidScan" class="btn btn-sm btn-secondary">Cancel</button>
                        <button type="button" wire:click="voidScan" wire:loading.attr="disabled" class="btn btn-sm btn-danger">Void scan</button>
                    </div>
                </div>
            @else
                <div>
                    <button type="button" wire:click="startVoidScan({{ $scan->id }})" class="btn btn-sm btn-ghost is-danger">Void</button>
                </div>
            @endif
        @endif
    </article>
@empty
    <p class="ui-hint">No scans attached.</p>
@endforelse
