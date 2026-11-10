{{-- The computed state of a batch (FinPvBatch::computeState): a badge with an icon AND a word, never colour alone. --}}
@props(['state'])
@php
    $tone = ['draft' => 'neutral', 'awaiting_assignment' => 'warning', 'in_progress' => 'primary', 'completed' => 'success', 'cancelled' => 'neutral'][$state['key']] ?? 'neutral';
    $icon = ['draft' => 'pencil', 'awaiting_assignment' => 'clock', 'in_progress' => 'loader-circle', 'completed' => 'check', 'cancelled' => 'circle-x'][$state['key']] ?? 'minus';
@endphp
<span class="ui-badge ui-badge-{{ $tone }}"><x-ui.icon :name="$icon" /> <span>{{ $state['label'] }}</span></span>
