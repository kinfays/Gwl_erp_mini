<div>
    <x-ui.page-header title="Acting Assignments" description="Someone acting as chief manager, regional chief manager or Managing Director can approve leave for that post between the dates below, alongside its holder." />

    @if (session('success'))
        <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Assignments" :padded="false">
            <x-ui.table label="Acting assignments">
                <x-slot:head>
                    <tr>
                        <th>Acting</th>
                        <th>Post</th>
                        <th>Scope</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>
                @forelse ($assignments as $assignment)
                    <tr wire:key="acting-{{ $assignment->id }}">
                        <td>{{ $assignment->user?->full_name }} <span class="ui-person-sub">#{{ $assignment->user?->staff_id }}</span></td>
                        <td>{{ $roles[$assignment->acting_for_role] ?? $assignment->acting_for_role }}</td>
                        <td>{{ $assignment->region?->region_name ?? $assignment->department?->department_name ?? 'Any' }}</td>
                        <td class="nowrap">{{ $assignment->starts_on->format('d M Y') }}</td>
                        <td class="nowrap">{{ $assignment->ends_on->format('d M Y') }}</td>
                        <td>
                            @if (! $assignment->is_active)
                                <x-ui.badge tone="neutral">Off</x-ui.badge>
                            @elseif ($assignment->isActiveOn())
                                <x-ui.badge tone="success">In force</x-ui.badge>
                            @elseif ($assignment->starts_on->isFuture())
                                <x-ui.badge tone="info">Upcoming</x-ui.badge>
                            @else
                                <x-ui.badge tone="neutral">Ended</x-ui.badge>
                            @endif
                        </td>
                        <td class="actions">
                            @if ($canManage($assignment))
                                <div class="row-actions">
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="toggle({{ $assignment->id }})">{{ $assignment->is_active ? 'Switch off' : 'Switch on' }}</button>
                                    <button
                                        type="button"
                                        class="btn btn-danger btn-sm"
                                        x-data
                                        x-on:click.prevent="$dispatch('confirm-action', {
                                            title: 'Delete this assignment?',
                                            message: 'The person will no longer be able to approve as acting.',
                                            confirmLabel: 'Delete',
                                            variant: 'danger',
                                            action: () => $wire.delete({{ $assignment->id }})
                                        })"
                                    >Delete</button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="7" icon="users" title="No acting assignments." description="Add one when a chief manager, regional chief manager or the Managing Director is away." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Add an assignment">
            <x-ui.select label="Who is acting" wire:model="userId" id="acting-user" error="userId">
                <option value="">Choose a person</option>
                @foreach ($people as $person)
                    <option value="{{ $person->id }}">{{ $person->full_name }} ({{ $person->staff_id }})</option>
                @endforeach
            </x-ui.select>
            @error('user_id') <p class="ui-error"><span>{{ $message }}</span></p> @enderror

            <x-ui.select label="Acting as" wire:model.live="role" id="acting-role" error="role" :disabled="$ownRegionOnly">
                @foreach ($roles as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
            @error('acting_for_role') <p class="ui-error"><span>{{ $message }}</span></p> @enderror

            @if ($role === 'regional_chief_manager' || $role === 'managing_director')
                @if ($role === 'regional_chief_manager')
                    <x-ui.select label="Region" wire:model="regionId" id="acting-region" :disabled="$ownRegionOnly">
                        <option value="">Choose a region</option>
                        @foreach ($regionOptions as $region)
                            <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                        @endforeach
                    </x-ui.select>
                    @error('region_id') <p class="ui-error"><span>{{ $message }}</span></p> @enderror
                @endif
            @else
                <x-ui.select label="Department" wire:model="departmentId" id="acting-department">
                    <option value="">Choose a department</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                    @endforeach
                </x-ui.select>
                @error('department_id') <p class="ui-error"><span>{{ $message }}</span></p> @enderror
            @endif

            <x-ui.input type="date" label="From" wire:model="startsOn" id="acting-starts" error="startsOn" />
            <x-ui.input type="date" label="To (included)" wire:model="endsOn" id="acting-ends" error="endsOn" />
            @error('ends_on') <p class="ui-error"><span>{{ $message }}</span></p> @enderror

            <x-slot:footer>
                <div class="ui-form-actions">
                    <button type="button" class="btn btn-primary" wire:click="save">Save assignment</button>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </div>
</div>
