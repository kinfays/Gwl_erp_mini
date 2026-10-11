<div>
    <x-ui.page-header title="Purchases (trips)" description="Each collection trip from G Water: the waybill, the packs bought, the vehicle, the fuel and the loading and offloading.">
        @if ($canRecord && $location)
            <x-slot:actions>
                <x-ui.button variant="primary" icon="plus" wire:click="openForm">Record a trip</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('livewire.gwater.partials.location-picker')

    @if ($location)
        <x-ui.card class="dash-row">
            <div class="ui-form-grid">
                <x-ui.input label="From" type="date" wire:model.live="from" />
                <x-ui.input label="To" type="date" wire:model.live="to" />
            </div>
        </x-ui.card>

        <x-ui.card :padded="false" class="dash-row">
            <x-ui.table label="Trips">
                <x-slot:head>
                    <tr>
                        <th>Date</th><th>Waybill</th><th>Packs</th><th class="num">Cost</th><th>Vehicle</th><th class="num">Fuel</th><th class="num">Loading / offloading</th><th>Status</th>
                        <th><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>
                @forelse ($purchases as $trip)
                    <tr wire:key="trip-{{ $trip->id }}" @class(['is-voided' => $trip->isVoided()])>
                        <td class="nowrap">{{ $trip->purchase_date->format('d M Y') }}</td>
                        <td class="mono">{{ $trip->waybill_no }}</td>
                        <td>
                            @foreach ($trip->lines as $line)
                                <div>{{ number_format($line->packs) }} × {{ $line->product->name }} <span class="cell-muted">@ {{ \App\Services\GWater\Money::format($line->unit_cost) }}</span></div>
                            @endforeach
                        </td>
                        <td class="num">{{ \App\Services\GWater\Money::format($trip->totalCost()) }}</td>
                        <td>
                            @if ($trip->vehicle)
                                <span class="mono">{{ $trip->vehicle->number_plate }}</span>
                            @endif
                            <div class="cell-muted">{{ $trip->driver?->full_name }}</div>
                        </td>
                        <td class="num">
                            {{ \App\Services\GWater\Money::format($summary->tripFuel($trip)) }}
                            @if ($trip->vehicle_expense_id)
                                <div><x-ui.badge tone="lagoon">From Transport</x-ui.badge></div>
                                @if ($summary->changedInTransport($trip))
                                    <div class="cell-muted">changed in Transport</div>
                                @endif
                            @endif
                            @if ($trip->fuel_reimbursement_status === 'pending')
                                <div><x-ui.badge tone="warning">To pay back</x-ui.badge></div>
                            @elseif ($trip->fuel_reimbursement_status === 'reimbursed')
                                <div><x-ui.badge tone="success">Paid back</x-ui.badge></div>
                            @endif
                        </td>
                        <td class="num">
                            {{ \App\Services\GWater\Money::format($trip->expenses->where('category', 'loading')->reject(fn ($e) => $e->isVoided())->sum('amount')) }}
                            / {{ \App\Services\GWater\Money::format($trip->expenses->where('category', 'offloading')->reject(fn ($e) => $e->isVoided())->sum('amount')) }}
                        </td>
                        <td>
                            @if ($trip->isVoided())
                                <x-ui.badge tone="danger">Voided</x-ui.badge>
                                <div class="cell-muted">{{ $trip->void_reason }}</div>
                            @else
                                <x-ui.badge tone="success">Posted</x-ui.badge>
                            @endif
                        </td>
                        <td class="nowrap">
                            @if ($canVoid && ! $trip->isVoided())
                                <x-ui.button size="sm" variant="ghost" wire:click="openVoid({{ $trip->id }})">Void</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="9" icon="boxes" title="No trips in this period." />
                @endforelse
                <x-slot:footer>
                    <div>{{ $purchases->links() }}</div>
                </x-slot:footer>
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($showForm && $canRecord && $location)
        <x-ui.modal title="Record a trip" description="Packs are added to stock at the cost price in force on the trip date." size="lg" close="closeForm" icon="boxes">
            <div class="ui-form-grid">
                <x-ui.input label="Trip date" type="date" wire:model.live="form.purchase_date" :max="today()->toDateString()" />
                <x-ui.input label="Waybill number" wire:model.defer="form.waybill_no" maxlength="30" class="mono" />

                @foreach ($products as $product)
                    <x-ui.input
                        :label="'Packs of '.strtolower($product->code).' bought'"
                        type="number" min="0" step="1" inputmode="numeric"
                        wire:model.defer="packs.{{ $product->id }}"
                        :hint="'Cost per pack: '.(($costs[$product->id] ?? null) !== null ? \App\Services\GWater\Money::format($costs[$product->id]) : 'none on the price list')"
                    />
                @endforeach
                <x-ui.input label="Supplier reference (optional)" wire:model.defer="form.supplier_ref" maxlength="60" />
            </div>
            @error('form.lines') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror

            <h3 class="ui-panel-title" style="margin-top:16px">Vehicle and fuel (read from Transport)</h3>
            <div class="ui-form-grid">
                <x-ui.select label="Vehicle" wire:model.live="form.vehicle_id" hint="Only vehicles based at your location.">
                    <option value="">No vehicle</option>
                    @foreach ($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }} - {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Driver" wire:model.defer="form.driver_id">
                    <option value="">No driver</option>
                    @foreach ($drivers as $driver)
                        <option value="{{ $driver->id }}">{{ $driver->full_name ?? $driver->email }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Mileage entry" wire:model.defer="form.mileage_log_id" hint="Gives the distance of the run.">
                    <option value="">None</option>
                    @foreach ($mileageLogs as $log)
                        <option value="{{ $log->id }}">{{ $log->trip_date->format('d M Y') }} · {{ $log->trip_purpose }} · {{ number_format($log->distance_driven) }} km</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Fuel expense in Transport" wire:model.live="form.vehicle_expense_id" hint="One fuel expense can belong to one trip only.">
                    <option value="">None (enter fuel below)</option>
                    @foreach ($fuelExpenses as $expense)
                        <option value="{{ $expense->id }}">{{ $expense->expense_date->format('d M Y') }} · GH¢ {{ number_format((float) $expense->amount, 2) }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <h3 class="ui-panel-title" style="margin-top:16px">Costs of the trip (GH¢)</h3>
            <div class="ui-form-grid">
                <x-ui.input label="Fuel (when not taken from Transport)" wire:model.live="form.fuel_amount" inputmode="decimal" :disabled="$form['vehicle_expense_id'] !== ''" />
                <x-ui.select label="Fuel paid out of pocket by" wire:model.defer="form.fuel_paid_by_user_id" hint="Leave empty if the company paid. Only fuel is paid back.">
                    <option value="">Paid by the company</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}">{{ $person->full_name ?? $person->email }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Loading (paid in cash)" wire:model.defer="form.loading_amount" inputmode="decimal" />
                <x-ui.input label="Offloading (paid in cash)" wire:model.defer="form.offloading_amount" inputmode="decimal" />
                <x-ui.input label="Paid to (optional)" wire:model.defer="form.payee" maxlength="120" />
                <x-ui.input label="Receipt reference (optional)" wire:model.defer="form.receipt_ref" maxlength="60" />
                <div class="span-2">
                    <x-ui.textarea label="Note (optional)" wire:model.defer="form.note" rows="2" />
                </div>
            </div>
            @error('form.location') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            @error('form.packs') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror

            <x-slot:footer>
                <x-ui.button wire:click="closeForm">Cancel</x-ui.button>
                <x-ui.button variant="primary" icon="check" wire:click="save" loading="save">Save trip</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($voidingId)
        <x-ui.modal title="Void this trip" description="The packs leave stock again, its costs are voided and its Transport fuel is released. The trip stays in the books, marked voided." close="cancelVoid">
            <x-ui.textarea label="Reason" wire:model.defer="voidReason" rows="3" maxlength="500" />
            @error('void.reason') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            @error('void.packs') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="cancelVoid">Cancel</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="confirmVoid">Void trip</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
