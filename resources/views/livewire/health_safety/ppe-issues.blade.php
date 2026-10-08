<div>
    <x-ui.page-header title="PPE issues" description="What staff hold, and when it is due for replacement.">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button :href="route('health_safety.export', ['report' => 'ppe-issues'] + $exportFilters)" icon="file-spreadsheet">Excel</x-ui.button>
            @endif
            @if ($canManage)
                <x-ui.button :href="route('health_safety.ppe.import')" icon="upload">Import what staff already hold</x-ui.button>
                <x-ui.button variant="primary" icon="user-plus" wire:click="startIssue">Issue to staff</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Issue to staff, with the replacement step --}}
    @if ($panel === 'issue')
        <x-ui.card title="Issue PPE to a member of staff" class="dash-row">
            <form wire:submit="saveIssue" class="ui-stack" novalidate>
                <div class="ui-form-grid">
                    <div>
                        @if ($issueEmployeeId)
                            <span class="ui-label">Member of staff</span>
                            <p>{{ $issueEmployeeLabel }} <x-ui.button size="sm" variant="ghost" wire:click="clearEmployee">Change</x-ui.button></p>
                        @else
                            <x-ui.input label="Find by staff ID or name" wire:model.live.debounce.300ms="employeeSearch" error="employee_id" required hint="Active staff in your region only." />
                            @error('issueEmployeeId')<p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>@enderror
                            @foreach ($employeeMatches as $match)
                                <x-ui.button size="sm" variant="ghost" wire:key="hs-ppe-emp-{{ $match->id }}" wire:click="chooseEmployee({{ $match->id }})">{{ $match->full_name }} <span class="mono">{{ $match->staff_id }}</span> <span class="cell-muted">{{ $match->jobTitle?->job_title_name }}</span></x-ui.button>
                            @endforeach
                        @endif
                    </div>

                    <x-ui.select label="PPE type" wire:model.live="issueTypeId" error="ppe_type_id" required>
                        <option value="">Select type</option>
                        @foreach ($types as $type)@if ($type->is_active)<option value="{{ $type->id }}">{{ $type->name }}</option>@endif @endforeach
                    </x-ui.select>

                    @if ($issueType?->has_sizes)
                        <x-ui.select label="Size" wire:model="issueSize" error="size" required>
                            <option value="">Select size</option>
                            @foreach ($issueType->sizeList() as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach
                        </x-ui.select>
                    @endif

                    <x-ui.input type="number" min="1" inputmode="numeric" label="Quantity" wire:model="issueQuantity" error="quantity" required />

                    @if ($issueType?->has_expiry)
                        <x-ui.input type="date" label="Expires on" wire:model="issueExpiresOn" error="expires_on" required hint="This item has its own expiry date." />
                    @endif

                    @if (! $issueHistoric)
                        <x-ui.select label="From store" wire:model.live="issueStoreId" error="store_id" required>
                            <option value="">Select store</option>
                            @foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach
                        </x-ui.select>
                        @php $backdate = (int) \App\Services\HealthSafety\HealthSafetySettings::value('hs_issue_backdate_days'); @endphp
                        <x-ui.input type="date" label="Given out on" wire:model="issueIssuedOn" error="issued_on" required
                            :min="today()->subDays($backdate)->toDateString()" :max="today()->toDateString()"
                            :hint="$backdate > 0 ? 'Today, or up to '.$backdate.' day'.($backdate === 1 ? '' : 's').' back.' : 'Today.'" />
                    @else
                        <x-ui.input type="date" label="Given out on" wire:model="issueIssuedOn" error="issued_on" required :max="today()->toDateString()" />
                    @endif

                    <div class="span-2">
                        <x-ui.checkbox label="Already held (do not deduct from stock)" description="Records something they have had for a while: nothing is taken from any store, and the date can be in the past." wire:model.live="issueHistoric" />
                    </div>
                </div>

                @if ($held->isNotEmpty())
                    <div class="ui-stack">
                        <h3 class="ui-label">They already hold this: what happens to it?</h3>
                        <p class="ui-hint">Anything due or overdue is set to worn out and will be closed with this issue. Leave a row as "Keep" to leave it open.</p>
                        @error('closings')<p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>@enderror
                        @foreach ($held as $row)
                            <div class="ui-form-grid" wire:key="hs-ppe-held-{{ $row->id }}">
                                <div class="span-2">
                                    {{ $row->quantity }} &times; {{ $row->size ? 'size '.$row->size.', ' : '' }}issued {{ $row->issued_on->format('d M Y') }}
                                    @if ($row->replace_due_on) <span class="cell-muted">due {{ $row->replace_due_on->format('d M Y') }}</span>@endif
                                    @if ($row->state() !== 'ok') <x-ui.status-pill domain="hs-ppe-issue" :status="$row->state()" />@endif
                                </div>
                                <x-ui.select label="Outcome" wire:model.live="closings.{{ $row->id }}.outcome">
                                    <option value="">Keep (leave open)</option>
                                    @foreach ($outcomes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                </x-ui.select>
                                @if (($closings[$row->id]['outcome'] ?? '') === 'returned' && ! $row->is_historic)
                                    <x-ui.select label="Returned to store" wire:model="closings.{{ $row->id }}.store_id" error="store_id">
                                        <option value="">Select store</option>
                                        @foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach
                                    </x-ui.select>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="ui-form-actions">
                    <x-ui.button wire:click="cancel">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading="saveIssue">Issue</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- Close an issue --}}
    @if ($panel === 'close' && $closingIssue)
        <x-ui.card :title="'Close: '.$closingIssue->type->name.' for '.$closingIssue->employee->full_name" class="dash-row">
            <form wire:submit="saveClose" class="ui-stack" novalidate>
                <div class="ui-form-grid">
                    <x-ui.select label="How did it end?" wire:model.live="closeOutcome" required>
                        <option value="">Select</option>
                        @foreach ($outcomes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </x-ui.select>
                    <x-ui.input type="date" label="On" wire:model="closeOn" error="closed_on" required />
                    @if ($closeOutcome === 'returned' && ! $closingIssue->is_historic)
                        <x-ui.select label="Returned to store" wire:model="closeStoreId" error="store_id" required>
                            <option value="">Select store</option>
                            @foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach
                        </x-ui.select>
                    @endif
                    <div class="span-2"><x-ui.textarea label="Note (optional)" wire:model="closeNote" rows="2" /></div>
                </div>
                @error('status')<p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>@enderror
                <div class="ui-form-actions">
                    <x-ui.button wire:click="cancel">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading="saveClose">Close issue</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :padded="false" class="dash-row">
        <div class="ui-toolbar" role="search" aria-label="Filter issues">
            <input type="search" class="form-input" wire:model.live.debounce.300ms="search" placeholder="Staff ID or name" aria-label="Search by staff">
            <select class="form-input" wire:model.live="typeId" aria-label="PPE type">
                <option value="">All types</option>
                @foreach ($types as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach
            </select>
            <select class="form-input" wire:model.live="status" aria-label="Status">
                <option value="open">Open issues</option>
                <option value="all">All, including closed</option>
                @foreach ($statuses as $value => $label)@if ($value !== 'issued')<option value="{{ $value }}">{{ $label }} only</option>@endif @endforeach
            </select>
            <select class="form-input" wire:model.live="state" aria-label="State">
                <option value="">Any state</option>
                <option value="overdue">Overdue</option>
                <option value="replacement_due">Replacement due</option>
                <option value="ok">OK</option>
            </select>
        </div>

        <x-ui.table label="PPE issues" pin-first>
            <x-slot:head>
                <tr><th>Staff</th><th>PPE</th><th class="num">Qty</th><th>Issued</th><th>Replace by</th><th>Status</th><th>Receipt</th>@if ($canManage)<th class="actions"><span class="sr-only-text">Actions</span></th>@endif</tr>
            </x-slot:head>
            @forelse ($issues as $issue)
                @php $state = $issue->state(); @endphp
                <tr wire:key="hs-ppe-issue-{{ $issue->id }}">
                    <td>{{ $issue->employee?->full_name }}<br><span class="ui-person-sub mono">{{ $issue->employee?->staff_id }}</span> <span class="ui-person-sub">{{ $issue->employee?->jobTitle?->job_title_name }}</span></td>
                    <td>{{ $issue->type?->name }}@if ($issue->size) <span class="cell-muted">({{ $issue->size }})</span>@endif</td>
                    <td class="num">{{ $issue->quantity }}</td>
                    <td class="nowrap">{{ $issue->issued_on->format('d M Y') }}@if ($issue->is_historic) <x-ui.badge>Already held</x-ui.badge>@endif</td>
                    <td class="nowrap">{{ $issue->replace_due_on?->format('d M Y') ?? '—' }}</td>
                    <td>
                        @if ($state)<x-ui.status-pill domain="hs-ppe-issue" :status="$state" />@else<x-ui.status-pill domain="hs-ppe-issue" :status="$issue->status" />@endif
                        @if ($issue->closed_on)<span class="ui-person-sub">{{ $issue->closed_on->format('d M Y') }}</span>@endif
                    </td>
                    <td>@if ($issue->acknowledged_at)<x-ui.badge tone="success">Confirmed</x-ui.badge>@elseif ($issue->isOpen())<span class="cell-muted">Not yet</span>@endif</td>
                    @if ($canManage)
                        <td class="actions">@if ($issue->isOpen())<x-ui.button size="sm" wire:click="startClose({{ $issue->id }})">Close</x-ui.button>@endif</td>
                    @endif
                </tr>
            @empty
                <x-ui.empty-row :colspan="$canManage ? 8 : 7" icon="inbox" title="No issues match these filters." />
            @endforelse

            @if ($issues->hasPages())
                <x-slot:footer><div class="pager-end">{{ $issues->links() }}</div></x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
