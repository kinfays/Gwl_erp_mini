{{-- X11: the executive summary by district: the X7 headline figures and what needs attention --}}
@include('livewire.commercial.cross.x7')

@if ($parts['flags']['data'] === null)
    <x-commercial.cross-part :part="$parts['flags']" />
@else
<x-commercial.cross-part :part="$parts['flags']" description="What needs attention, with a link to where it is listed. Counts only cover the parts you may see.">
    @php $flags = $parts['flags']['data']['flags']; @endphp
    <x-ui.table label="What needs attention">
        <x-slot:head><tr><th>Flag</th><th class="num">Count</th><th>Where</th></tr></x-slot:head>
        @foreach ($flags as $flag)
            <tr data-flag="{{ $flag['key'] }}" @if ($flag['count'] > 0) style="font-weight:600" @endif>
                <td>{{ $flag['label'] }}@if (! empty($flag['detail'])) <span class="ui-hint">({{ implode('; ', $flag['detail']) }})</span>@endif</td>
                <td class="num">{{ number_format($flag['count']) }}</td>
                <td>
                    @if ($flag['link'] === 'home')
                        <a href="{{ route('commercial.home') }}">Uploads</a>
                    @else
                        <a href="{{ route('commercial.cross.show', ['analysis' => $flag['link'], ...$flag['query']]) }}">{{ strtoupper($flag['link']) }}</a>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>
</x-commercial.cross-part>
@endif
