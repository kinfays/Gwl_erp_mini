<div>
    <x-ui.page-header title="MDM Policies" description="What every enrolled phone must look like: apps, restrictions and anti-theft settings.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('assets.mdm.policies.create') }}" class="btn btn-primary">
                    <x-ui.icon name="plus" />
                    New Policy
                </a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.card :padded="false">
        <x-ui.table label="MDM policies" pin-first>
            <x-slot:head>
                <tr>
                    <th>Policy</th>
                    <th class="num">Apps</th>
                    <th class="num">Phones</th>
                    <th>Google</th>
                    <th class="actions"><span class="sr-only-text">Actions</span></th>
                </tr>
            </x-slot:head>

            @forelse ($policies as $policy)
                <tr wire:key="policy-{{ $policy->id }}">
                    <td>
                        <span class="ui-cell-stack">
                            <span class="ui-person-name">{{ $policy->name }}</span>
                            <span class="ui-person-sub">{{ $policy->description }}</span>
                        </span>
                    </td>
                    <td class="num">{{ $policy->apps->where('is_enabled', true)->count() }}</td>
                    <td class="num">{{ $policy->devices_count }}</td>
                    <td>
                        @if (! $policy->isPublished())
                            <x-ui.status-pill tone="muted" label="Never published" />
                        @elseif ($unpublished[$policy->id])
                            <x-ui.status-pill tone="warning" label="Unpublished changes" />
                            <span class="ui-hint">v{{ $policy->version }}</span>
                        @else
                            <x-ui.status-pill tone="success" label="Published" />
                            <span class="ui-hint">v{{ $policy->version }} · {{ $policy->published_at?->diffForHumans() }}</span>
                        @endif
                    </td>
                    <td class="actions">
                        @if ($canManage)
                            <a href="{{ route('assets.mdm.policies.edit', $policy->id) }}" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $policy->name }}">
                                <x-ui.icon name="pencil" />
                            </a>
                            <button type="button" wire:click="delete({{ $policy->id }})" wire:confirm="Delete the policy “{{ $policy->name }}”?" class="btn btn-ghost btn-sm btn-icon" title="Delete" aria-label="Delete {{ $policy->name }}">
                                <x-ui.icon name="trash-2" />
                            </button>
                        @else
                            <span class="ui-hint">Read only</span>
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" icon="shield-check" title="No policies yet." description="Create a policy, then publish it to Google before enrolling phones." />
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
