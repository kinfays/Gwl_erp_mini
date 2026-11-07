{{--
    The Audit / Finance mark of a payment voucher: a tick (acknowledged), an X (queried) or a clock (waiting). Never colour
    alone: every mark is an icon AND a visible word. The summary is the tooltip; the same facts open in a small popover on
    click, tap or keyboard focus (who, when, why). `mark` comes from FinPaymentVoucher::marks().
--}}
@props(['mark', 'party'])

@php
    $state = $mark['state'];
    $tone = ['ticked' => 'success', 'queried' => 'danger', 'waiting' => 'warning', 'voided' => 'neutral'][$state] ?? 'neutral';
    $icon = ['ticked' => 'check', 'queried' => 'x', 'waiting' => 'clock', 'voided' => 'circle-x'][$state] ?? 'minus';
    $summary = $party.': '.$mark['word']
        .($mark['by'] ? ' by '.$mark['by'] : '')
        .($mark['at'] ? ' on '.$mark['at']->format('d M Y H:i') : '')
        .($mark['reason'] ? '. '.$mark['reason'] : '');
@endphp

@if ($state === 'none')
    <span class="cell-muted" title="{{ $mark['detail'] }}">{{ $mark['word'] }}</span>
@else
    <span
        class="fin-mark"
        x-data="{ open: false, top: 0, left: 0, show() { const r = $refs.btn.getBoundingClientRect(); this.top = r.bottom + 6; this.left = Math.max(8, Math.min(r.left, window.innerWidth - 330)); this.open = true; } }"
        @keydown.escape="open = false"
        @focusout="if (! $el.contains($event.relatedTarget)) open = false"
        @click.outside="open = false"
        @scroll.window="open = false"
    >
        <button type="button" x-ref="btn" class="fin-mark-btn" title="{{ $summary }}" aria-haspopup="true" x-bind:aria-expanded="open.toString()" @click="show()" @focus="show()">
            <span class="sr-only-text">{{ $party }}:</span>
            <span class="ui-badge ui-badge-{{ $tone }}"><x-ui.icon :name="$icon" /> <span>{{ $mark['word'] }}</span></span>
        </button>
        <span class="fin-mark-pop" x-show="open" x-cloak role="status" x-bind:style="`top:${top}px;left:${left}px`">
            <strong>{{ $party }}: {{ $mark['word'] }}</strong>
            <dl>
                @if ($mark['by'])
                    <dt>{{ $state === 'waiting' ? 'Last action by' : 'By' }}</dt>
                    <dd>{{ $mark['by'] }}@if ($mark['at']), {{ $mark['at']->format('d M Y H:i') }}@endif</dd>
                @endif
                @if ($mark['reason'])
                    <dt>Reason</dt>
                    <dd>{{ $mark['reason'] }}</dd>
                @endif
                @if (filled($mark['comment']))
                    <dt>Comment</dt>
                    <dd>{{ $mark['comment'] }}</dd>
                @endif
                @if (filled($mark['detail']))
                    <dd>{{ $mark['detail'] }}</dd>
                @endif
            </dl>
        </span>
    </span>
@endif
