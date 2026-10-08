<div>
    <x-ui.page-header title="PPE entitlements" description="How many of each PPE a job title should hold. No number means no entitlement, and a job title with none is not checked for gaps." />

    @if ($types->isEmpty())
        <x-ui.alert tone="info" class="dash-row">There are no active PPE types yet. <a href="{{ route('health_safety.ppe.types') }}">Add the types</a> first.</x-ui.alert>
    @endif

    @if ($editingTitle)
        <x-ui.card :title="'Entitlements: '.$editingTitle->job_title_name" description="Leave a box empty for no entitlement." class="dash-row">
            <form wire:submit="save" class="ui-stack" novalidate>
                <div class="ui-form-grid">
                    @foreach ($types as $type)
                        <x-ui.input type="number" min="1" inputmode="numeric" :label="$type->name" wire:model="quantities.{{ $type->id }}" error="quantities.{{ $type->id }}" wire:key="hs-ent-{{ $type->id }}" />
                    @endforeach
                </div>
                <div class="ui-form-actions">
                    <x-ui.button wire:click="cancel">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading="save">Save</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :padded="false" class="dash-row">
        <div class="ui-toolbar" role="search" aria-label="Find a job title">
            <input type="search" class="form-input" wire:model.live.debounce.300ms="search" placeholder="Job title" aria-label="Search job titles">
        </div>

        <x-ui.table label="PPE entitlements by job title" pin-first>
            <x-slot:head>
                <tr>
                    <th>Job title</th>
                    <th class="num">Staff</th>
                    @foreach ($types as $type)<th class="num">{{ $type->name }}</th>@endforeach
                    <th class="actions"><span class="sr-only-text">Edit</span></th>
                </tr>
            </x-slot:head>
            @forelse ($titles as $title)
                <tr wire:key="hs-ent-title-{{ $title->id }}">
                    <td>{{ $title->job_title_name }}</td>
                    <td class="num">{{ $staffCounts[$title->id] ?? 0 }}</td>
                    @foreach ($types as $type)
                        <td class="num">{{ ($cells[$title->id][$type->id] ?? null) ?: '—' }}</td>
                    @endforeach
                    <td class="actions"><x-ui.button size="sm" wire:click="edit({{ $title->id }})">Edit</x-ui.button></td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="3 + $types->count()" icon="users" title="No job titles match." />
            @endforelse

            @if ($titles->hasPages())
                <x-slot:footer><div class="pager-end">{{ $titles->links() }}</div></x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
