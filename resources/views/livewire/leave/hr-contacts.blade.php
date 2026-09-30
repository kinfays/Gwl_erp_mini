<div>
    <x-ui.page-header title="HR Contacts" description="Who HR is told, by email and in the portal, when leave is finally approved for staff in their scope." />

    @if (session('success'))
        <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Contacts by scope" description="Head Office staff go to the Head Office contact; everyone else to their region's." :padded="false">
            <x-ui.table label="HR contacts by scope">
                <x-slot:head>
                    <tr>
                        <th>Scope</th>
                        <th>Email</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($rows as $row)
                    @php($contact = $row['contact'])
                    <tr wire:key="hr-contact-{{ $row['scope'] }}" @class(['is-selected' => $scope === $row['scope']])>
                        <td>{{ $row['label'] }}</td>
                        <td @class(['cell-muted' => ! $contact])>{{ $contact?->email ?? 'Not set' }}</td>
                        <td @class(['cell-muted' => ! $contact?->name])>{{ $contact?->name ?: '-' }}</td>
                        <td>
                            @if ($contact)
                                <x-ui.badge :tone="$contact->is_active ? 'success' : 'neutral'">{{ $contact->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                            @else
                                <span class="cell-muted">-</span>
                            @endif
                        </td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" wire:click="edit('{{ $row['scope'] }}')" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $row['label'] }} contact">
                                    <x-ui.icon name="pencil" />
                                </button>
                                @if ($contact)
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm btn-icon is-danger"
                                        title="Remove"
                                        aria-label="Remove {{ $row['label'] }} contact"
                                        x-data
                                        x-on:click.prevent="$dispatch('confirm-action', {
                                            title: 'Remove HR contact?',
                                            message: @js('Approved leave for ' . $row['label'] . ' will no longer be emailed to ' . $contact->email . '. HR users are still notified in the portal.'),
                                            confirmLabel: 'Remove',
                                            variant: 'danger',
                                            action: () => $wire.delete({{ $contact->id }})
                                        })"
                                    >
                                        <x-ui.icon name="trash-2" />
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="5" icon="mail" title="No scopes to manage." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="$editingLabel ? 'Contact for '.$editingLabel : 'HR Contact'" class="manager-form">
            @if ($scope === '')
                <x-ui.empty-state icon="mail" title="Pick a scope" description="Choose Edit on a row to set its HR contact." />
            @else
                <div class="ui-stack">
                    <x-ui.input label="Email" type="email" wire:model="email" placeholder="hr@example.com" id="hr-contact-email" wire:key="hr-contact-email-{{ $scope }}" />
                    <x-ui.input label="Name (optional)" wire:model="name" placeholder="e.g. Regional HR Desk" id="hr-contact-name" wire:key="hr-contact-name-{{ $scope }}" />
                    <x-ui.checkbox label="Send emails to this contact" id="hr-contact-active" wire:model="isActive" wire:key="hr-contact-active-{{ $scope }}" />
                    @error('scope')
                        <p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>
                    @enderror

                    <div class="ui-form-actions">
                        <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Cancel</button>
                        <button type="button" wire:click="save" class="btn btn-primary">Save</button>
                    </div>
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
