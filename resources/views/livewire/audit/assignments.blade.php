<div>
    <x-ui.page-header title="Assignments" description="Vouchers and batches Finance has sent to Audit. Nobody in Audit can work on one until you give it to an officer. You see only a reference, the amount and who holds it; the voucher itself opens once an officer has acted on it.">
    </x-ui.page-header>

    @if ($isSuperAdmin)
        <x-ui.card class="dash-row">
            <x-ui.select label="Location (the vouchers you are working in)" wire:model.live="locationId">
                <option value="">Choose a location</option>
                @foreach ($locationChoices as $district)
                    <option value="{{ $district->id }}">{{ $district->district_name }} - {{ $district->region?->region_name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.card>
    @endif

    @unless ($location)
        <x-ui.alert tone="warning" role="status" class="dash-row">
            @if ($isSuperAdmin) Choose a location above to see its assignment queue. @else Your account has no location (an employee record with a region), so there is no queue for you here. Ask an administrator. @endif
        </x-ui.alert>
    @endunless

    <x-ui.card class="dash-row">
        <div class="ui-form-grid">
            <x-ui.select label="Show" wire:model.live="show">
                <option value="awaiting">Waiting for an officer</option>
                <option value="all">Everything sent to Audit</option>
            </x-ui.select>
            <x-ui.input label="Search" wire:model.live.debounce.400ms="search" placeholder="PV number, batch number or title" />
        </div>
    </x-ui.card>

    {{-- Who to give the picked vouchers to. The list holds only active Audit officers of this location who sent none of them. --}}
    @if ($pickedCount > 0)
        <x-ui.card title="Assign {{ $pickedCount }} {{ \Illuminate\Support\Str::plural('voucher', $pickedCount) }}" :description="$pickedBatches > 0 ? 'Including '.$pickedBatches.' '.\Illuminate\Support\Str::plural('batch', $pickedBatches).'. Every voucher of a batch that is waiting for Audit goes to the officer you choose.' : null" class="dash-row">
            <div class="ui-form-grid">
                <x-form.searchable-select label="Assign to" model="officerId" :options="$officerOptions" :reset-key="$pickedKey"
                    required placeholder="Type an officer's name" empty-text="No officer can take all of these"
                    hint="Active Audit officers of this location, with how many vouchers they have open. Anyone who sent one of these vouchers is left out." />
                <x-ui.input label="Note (optional)" wire:model="note" maxlength="500" hint="Shown on the voucher's timeline." />
            </div>
            @if ($choiceError)<x-ui.alert tone="warning" role="status">{{ $choiceError }}</x-ui.alert>@endif
            @foreach (['assign.vouchers', 'assign.officer', 'assign.note', 'assign.status'] as $key)
                @error($key) <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            @endforeach
            <div class="ui-form-actions">
                <x-ui.button variant="primary" icon="user-check" wire:click="assign" loading="assign">Assign</x-ui.button>
                <x-ui.button variant="ghost" wire:click="clearSelection">Clear selection</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card :padded="false" title="Batches" class="dash-row">
        <x-ui.table label="Batches sent to Audit">
            <x-slot:head>
                <tr>
                    <th><span class="sr-only-text">Select</span></th>
                    <th>Batch no.</th>
                    <th>Title</th>
                    <th class="num">Vouchers</th>
                    <th class="num">Total (GH¢)</th>
                    <th>State</th>
                    <th>Assigned to</th>
                    <th>Sent</th>
                    <th class="num">Days waiting</th>
                    <th><span class="sr-only-text">Open</span></th>
                </tr>
            </x-slot:head>
            @forelse ($batches as $batch)
                @php($summary = $summaries[$batch->id])
                <tr wire:key="ab-{{ $batch->id }}">
                    <td><input type="checkbox" wire:model.live="selectedBatches" value="{{ $batch->id }}" aria-label="Select batch {{ $batch->batch_no }}"></td>
                    <td class="mono nowrap"><a href="{{ route('audit.batches.show', $batch->id) }}">{{ $batch->batch_no }}</a></td>
                    <td>{{ $batch->title ?: '-' }}</td>
                    <td class="num">{{ $summary['count'] }}</td>
                    <td class="num">{{ $summary['total'] }}</td>
                    <td><x-finance.batch-state :state="$summary['state']" /></td>
                    <td>@forelse ($summary['officers'] as $name)<div>{{ $name }}</div>@empty<span class="cell-muted">Nobody yet</span>@endforelse</td>
                    <td class="nowrap">{{ $batch->sent_at?->format('d M Y') }}</td>
                    <td class="num">{{ $summary['waiting'] }}</td>
                    <td class="nowrap"><x-ui.button size="sm" variant="ghost" :href="route('audit.batches.show', $batch->id)">Open</x-ui.button></td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="10" icon="layers" title="No batches are waiting." />
            @endforelse
            <x-slot:footer><div>{{ $batches->links() }}</div></x-slot:footer>
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :padded="false" title="Single vouchers" description="Vouchers that were not sent in a batch." class="dash-row">
        <x-ui.table label="Single vouchers sent to Audit">
            <x-slot:head>
                <tr>
                    <th><span class="sr-only-text">Select</span></th>
                    <th>PV no.</th>
                    <th>Category / type</th>
                    <th class="num">Amount (GH¢)</th>
                    <th>Status</th>
                    <th>Sent</th>
                    <th class="num">Days waiting</th>
                    <th>Assigned to</th>
                </tr>
            </x-slot:head>
            @forelse ($loose as $pv)
                <tr wire:key="al-{{ $pv->id }}">
                    <td>
                        <input type="checkbox" wire:model.live="selectedPvs" value="{{ $pv->id }}" aria-label="Select voucher {{ $pv->pv_no }}"
                            @disabled($pv->status !== \App\Models\FinPaymentVoucher::WITH_AUDIT)
                            @if ($pv->status !== \App\Models\FinPaymentVoucher::WITH_AUDIT) title="Only a voucher that is with Audit can be assigned" @endif>
                    </td>
                    <td class="mono">{{ $pv->pv_no }}@if ($pv->round > 1) <span class="cell-muted">(round {{ $pv->round }})</span>@endif</td>
                    <td>{{ $pv->category_label }}<div class="cell-muted">{{ $pv->type_label }}</div></td>
                    <td class="num">{{ $pv->amountFormatted() }}</td>
                    <td>{{ $pv->statusLabel() }}</td>
                    <td class="nowrap">{{ $pv->submitted_at?->format('d M Y') }}</td>
                    <td class="num">{{ $pv->status === \App\Models\FinPaymentVoucher::WITH_AUDIT ? $pv->ageInDays() : '-' }}</td>
                    <td>
                        @if ($pv->assigned_to_user_id)
                            {{ $names[$pv->assigned_to_user_id] ?? 'Unknown user' }}
                            <div class="cell-muted">by {{ $names[$pv->assigned_by_user_id] ?? 'the manager' }}@if ($pv->assigned_at), {{ $pv->assigned_at->format('d M Y H:i') }}@endif</div>
                        @else
                            <span class="cell-muted">Awaiting assignment</span>
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="8" icon="receipt" title="No single vouchers are waiting." />
            @endforelse
            <x-slot:footer><div>{{ $loose->links() }}</div></x-slot:footer>
        </x-ui.table>
    </x-ui.card>
</div>
