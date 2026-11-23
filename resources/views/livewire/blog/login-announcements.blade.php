{{-- The window of login announcements. Mounted once in the ERP layout; it is empty unless a login just armed it. --}}
<div>
    @if ($open && isset($items[$index]))
        @php($item = $items[$index])
        @php($total = count($items))

        <div class="ann-backdrop" wire:key="ann-backdrop"></div>

        <div
            class="ann-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ann-title"
            aria-describedby="ann-teaser"
            tabindex="-1"
            wire:key="ann-dialog"
            x-data
            x-trap.inert.noscroll="true"
            x-on:keydown.escape.prevent.stop="$wire.close()"
        >
            <div class="ann-head">
                <p class="ann-kicker">
                    {{ __('Announcement') }}
                    @if ($total > 1)
                        <span class="ann-step">{{ __(':n of :total', ['n' => $index + 1, 'total' => $total]) }}</span>
                    @endif
                </p>
                <button type="button" class="icon-btn" wire:click="close" aria-label="{{ __('Close') }}">
                    <x-ui.icon name="x" />
                </button>
            </div>

            <div class="ann-body" wire:key="ann-item-{{ $item['id'] }}">
                @if ($item['thumb'])
                    <img class="ann-thumb" src="{{ $item['thumb'] }}" alt="" width="96" height="96" decoding="async">
                @endif
                <div class="ann-text">
                    <p class="ann-meta">{{ $item['kind'] }}@if ($item['district']) · {{ $item['district'] }}@endif</p>
                    <h2 id="ann-title">{{ $item['title'] }}</h2>
                    <p id="ann-teaser">{{ $item['teaser'] }}</p>
                    @if ($item['event'] || $item['venue'])
                        <p class="ann-event">
                            @if ($item['event'])<span>{{ __('When:') }} {{ $item['event'] }}</span>@endif
                            @if ($item['venue'])<span>{{ __('Where:') }} {{ $item['venue'] }}</span>@endif
                        </p>
                    @endif
                    @if ($item['until'])
                        <p class="ann-until">{{ __('Shown until :date', ['date' => $item['until']]) }}</p>
                    @endif
                </div>
            </div>

            <div class="ann-actions">
                <a class="btn btn-primary" href="{{ $item['url'] }}" target="_blank" rel="noopener" wire:click="opened" autofocus>{{ __('Read full article') }}</a>
                @if ($index + 1 < $total)
                    <button type="button" class="btn" wire:click="next">{{ __('Next') }}</button>
                @endif
                <button type="button" class="btn" wire:click="dismissCurrent">{{ __("Don't show again") }}</button>
                <button type="button" class="btn" wire:click="close">{{ __('Close') }}</button>
            </div>
        </div>
    @endif
</div>
