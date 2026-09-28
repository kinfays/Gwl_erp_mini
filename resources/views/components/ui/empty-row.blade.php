{{--
    Table row holding an empty state, for use in @forelse … @empty inside <x-ui.table>.
    Docs: docs/11-ui-components.md#table
--}}
@props([
    'colspan' => 1,
    'icon' => 'inbox',
    'title',
    'description' => null,
])

<tr {{ $attributes->class(['ui-empty-row']) }}>
    <td colspan="{{ $colspan }}">
        <x-ui.empty-state :icon="$icon" :title="$title" :description="$description">{{ $slot }}</x-ui.empty-state>
    </td>
</tr>
