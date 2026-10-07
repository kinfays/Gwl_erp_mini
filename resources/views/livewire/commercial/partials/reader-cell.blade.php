{{-- A reader in a table: name linking to their page, staff ID, and a flag when they are not in the staff directory. --}}
<a href="{{ route('commercial.reading.reader', array_filter(['staffId' => $row['staff_id'], 'from' => $from ?? '', 'to' => $to ?? ''])) }}">
    <span class="ui-cell-stack">
        <span class="ui-person-name">{{ $row['name'] }}</span>
        <span class="ui-person-sub mono">{{ $row['staff_id'] }}</span>
    </span>
</a>
@if (($row['match_status'] ?? null) === 'unmatched')
    <x-ui.badge tone="warning">Not in staff directory</x-ui.badge>
@endif
