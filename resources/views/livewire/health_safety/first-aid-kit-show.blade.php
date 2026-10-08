<div>
    <x-ui.page-header :title="$kit->asset_code" :description="$kit->typeLabel().' first aid kit at '.$kit->locationLabel()">
        <x-slot:actions>
            @if ($canManage)
                <x-ui.button :href="route('health_safety.labels.kits', ['id' => $kit->id])" icon="qr-code">Print label</x-ui.button>
                <x-ui.button :href="route('health_safety.kits.edit', $kit)" icon="pencil">Edit</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($kit->status === 'decommissioned')
        <x-ui.alert tone="warning" class="dash-row">This kit is decommissioned. It is kept for its history, and no more checks can be recorded on it.</x-ui.alert>
    @endif

    <div class="ui-tags dash-row">
        <x-ui.status-pill domain="hs-kit" :status="$state ?? $kit->status" :label="$state ? \App\Models\HsFirstAidKit::stateLabel($state) : $statuses[$kit->status]" />
        @if ($kit->vehicle_id)<x-ui.badge>In a vehicle</x-ui.badge>@endif
    </div>

    @error('status')<x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert>@enderror

    <x-ui.card title="Details" class="dash-row">
        <dl class="ui-form-grid">
            <div>
                <dt class="ui-label">Where</dt>
                <dd>
                    @if ($kit->vehicle_id)
                        @if ($canSeeVehicle)
                            <a href="{{ route('transport.vehicles') }}">Vehicle {{ $kit->vehicle?->number_plate }}</a>
                        @else
                            Vehicle {{ $kit->vehicle?->number_plate ?? '(removed)' }}
                        @endif
                    @else
                        {{ $kit->site?->name }}
                    @endif
                    @if ($kit->location_detail)<span class="cell-muted">, {{ $kit->location_detail }}</span>@endif
                </dd>
            </div>
            <div><dt class="ui-label">District</dt><dd>{{ $kit->district?->district_name ?? '—' }} <span class="cell-muted">{{ $kit->region?->region_name }}</span></dd></div>
            <div><dt class="ui-label">Responsible person</dt><dd>{{ $kit->responsible?->full_name ?? '—' }}</dd></div>
            <div>
                <dt class="ui-label">Last check</dt>
                <dd>
                    {{ $kit->last_checked_on?->format('d M Y') ?? 'Never checked' }}
                    @if ($kit->last_check_result)<x-ui.status-pill domain="hs-kit" :status="$kit->last_check_result" />@endif
                    <span class="ui-hint">Next due {{ $nextCheckDue->format('d M Y') }}</span>
                </dd>
            </div>
            @if ($kit->notes)<div class="span-2"><dt class="ui-label">Notes</dt><dd style="white-space:pre-line">{{ $kit->notes }}</dd></div>@endif
            @if ($kit->status === 'decommissioned')
                <div class="span-2"><dt class="ui-label">Decommissioned</dt><dd>{{ $kit->decommissioned_on?->format('d M Y') }}: {{ $kit->decommission_reason }}</dd></div>
            @endif
        </dl>
    </x-ui.card>

    @if ($canCheck || $canManage)
        <div class="ui-form-actions dash-row">
            @if ($canCheck)<x-ui.button variant="primary" icon="clipboard-check" wire:click="openPanel('check')">Record check</x-ui.button>@endif
            @if ($canManage)
                <x-ui.button icon="list-checks" wire:click="openPanel('contents')">Edit contents list</x-ui.button>
                @if ($kit->status === 'missing')
                    <x-ui.button wire:click="setMissing(false)">Found: back in service</x-ui.button>
                @else
                    <x-ui.button wire:click="setMissing(true)">Mark missing</x-ui.button>
                @endif
                <x-ui.button variant="danger" wire:click="openPanel('decommission')">Decommission</x-ui.button>
            @endif
        </div>
    @endif

    {{-- Record check: items stacked on one screen, big number inputs --}}
    @if ($panel === 'check')
        <x-ui.card title="Record a check" description="Count what the kit holds now and correct any dates. Tick Restocked if you topped it up." class="dash-row">
            <form wire:submit="recordCheck" class="ui-stack hs-check" novalidate>
                @forelse ($kit->items as $item)
                    <div class="ui-form-grid" wire:key="hs-chk-{{ $item->id }}">
                        <div class="span-2"><strong>{{ $item->item_name }}</strong> <span class="cell-muted">should hold {{ $item->required_qty }}</span></div>
                        <x-ui.input type="number" inputmode="numeric" min="0" label="Holds now" wire:model="itemEdits.{{ $item->id }}.current_qty" error="itemEdits.{{ $item->id }}.current_qty" />
                        <x-ui.input type="date" label="{{ $item->has_expiry ? 'Expires' : 'Expires (if it does)' }}" wire:model="itemEdits.{{ $item->id }}.expiry_date" error="itemEdits.{{ $item->id }}.expiry_date" />
                    </div>
                @empty
                    <x-ui.alert tone="info">This kit has no contents list yet. Edit the contents list first, or just record that it was looked at.</x-ui.alert>
                @endforelse
                <x-ui.toggle label="Restocked during this check" wire:model="check.restocked" />
                <x-ui.input type="date" label="Date of the check" wire:model="check.checked_on" required />
                <x-ui.textarea label="Note" wire:model="check.notes" rows="2" error="notes" />
                <div class="ui-form-actions">
                    <x-ui.button wire:click="closePanel">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading="recordCheck">Save check</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if ($panel === 'contents')
        <x-ui.card title="Contents list" description="What this kit should hold. Changing it does not change the template, and the template does not change this kit." class="dash-row">
            <form wire:submit="saveContents" class="ui-stack" novalidate>
                @foreach ($contents as $index => $row)
                    <div class="ui-form-grid" wire:key="hs-row-{{ $index }}">
                        <x-ui.input label="Item" wire:model="contents.{{ $index }}.item_name" />
                        <x-ui.input type="number" min="1" label="Should hold" wire:model="contents.{{ $index }}.required_qty" error="items.{{ $index }}.required_qty" />
                        <x-ui.checkbox label="Has an expiry date" wire:model="contents.{{ $index }}.has_expiry" />
                        <div><x-ui.button size="sm" variant="ghost" wire:click="removeContentRow({{ $index }})">Remove</x-ui.button></div>
                    </div>
                @endforeach
                <div><x-ui.button size="sm" icon="plus" wire:click="addContentRow">Add an item</x-ui.button></div>
                <div class="ui-form-actions">
                    <x-ui.button wire:click="closePanel">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading="saveContents">Save contents</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if ($panel === 'decommission')
        <x-ui.card title="Decommission this kit" description="It leaves every list and alert. Its history stays." class="dash-row">
            <div class="ui-stack">
                <x-ui.textarea label="Reason" wire:model="reason" rows="2" error="reason" required />
                <div class="ui-form-actions">
                    <x-ui.button wire:click="closePanel">Cancel</x-ui.button>
                    <x-ui.button variant="danger" wire:click="decommission" loading="decommission">Decommission</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card title="Contents" :padded="false" class="dash-row">
        <x-ui.table label="Kit contents">
            <x-slot:head><tr><th>Item</th><th class="num">Should hold</th><th class="num">Holds now</th><th>Expires</th><th>Status</th></tr></x-slot:head>
            @forelse ($kit->items as $item)
                <tr wire:key="hs-item-{{ $item->id }}">
                    <td>{{ $item->item_name }}</td>
                    <td class="num">{{ $item->required_qty }}</td>
                    <td class="num">{{ $item->current_qty }}</td>
                    <td class="nowrap">{{ $item->expiry_date?->format('d M Y') ?? ($item->has_expiry ? 'Not set' : '—') }}</td>
                    <td>
                        @if ($item->isExpired())<x-ui.badge tone="danger">Expired</x-ui.badge>
                        @elseif ($item->isExpiring())<x-ui.badge tone="warning">Expiring soon</x-ui.badge>@endif
                        @if ($item->isShort())<x-ui.badge tone="warning">Short by {{ $item->required_qty - $item->current_qty }}</x-ui.badge>@endif
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" icon="list-checks" title="This kit has no contents list yet.">
                    @if ($canManage)
                        <x-ui.button size="sm" wire:click="openPanel('contents')">Edit contents list</x-ui.button>
                        <x-ui.button size="sm" :href="route('health_safety.kit-templates')">Kit templates</x-ui.button>
                    @endif
                </x-ui.empty-row>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Check history" :padded="false" class="dash-row">
        <x-ui.table label="Checks">
            <x-slot:head><tr><th>Date</th><th>By</th><th>Result</th><th>Restocked</th><th>Notes</th></tr></x-slot:head>
            @forelse ($checks as $entry)
                <tr wire:key="hs-kc-{{ $entry->id }}">
                    <td class="nowrap">{{ $entry->checked_on->format('d M Y') }}</td>
                    <td>{{ $entry->checker?->full_name }}</td>
                    <td><x-ui.status-pill domain="hs-kit" :status="$entry->result" /></td>
                    <td>{{ $entry->restocked ? 'Yes' : 'No' }}</td>
                    <td>{{ $entry->notes }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" icon="clipboard-list" title="No checks recorded yet." />
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
