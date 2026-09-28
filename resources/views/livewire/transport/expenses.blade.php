<div>
    <x-ui.page-header title="Expenses" description="Vehicle costs, filters, totals, and export.">
        <x-slot:actions>
            <a
                class="btn btn-secondary"
                href="{{ route('transport.expenses.export', ['vehicle_id' => $vehicleFilter ?: null, 'expense_type' => $typeFilter ?: null, 'date_from' => $dateFrom ?: null, 'date_to' => $dateTo ?: null]) }}"
            >
                <x-ui.icon name="file-spreadsheet" />
                Export Excel
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Filtered Total" :value="number_format($total, 2)" icon="banknote" meta="GHS" />
        @foreach ($byType->take(3) as $row)
            <x-ui.stat-tile :label="str($row->expense_type)->replace('_', ' ')->title()->toString()" :value="number_format($row->total, 2)" icon="receipt" tone="muted" />
        @endforeach
    </div>

    <div class="ui-grid ui-grid-main dash-row">
        <x-ui.card title="Log Expense">
            <div class="ui-stack">
                <div class="ui-form-grid">
                    <x-ui.select label="Vehicle" wire:model="form.vehicle_id">
                        <option value="">Select vehicle</option>
                        @foreach ($vehicles as $vehicle)
                            <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }} - {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Type" wire:model="form.expense_type">
                        @foreach ($expenseTypes as $option)
                            <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input label="Amount" type="number" step="0.01" wire:model.defer="form.amount" inputmode="decimal" />
                    <x-ui.input label="Currency" maxlength="3" wire:model.defer="form.currency" class="mono" />

                    <x-ui.input label="Expense Date" type="date" wire:model.defer="form.expense_date" />
                    <x-ui.field label="Receipt Photo" for="f-expense-receipt" error="receipt">
                        <input id="f-expense-receipt" type="file" class="form-input" wire:model="receipt">
                    </x-ui.field>

                    <div class="span-2">
                        <x-ui.textarea label="Description" wire:model.defer="form.description" rows="3" />
                    </div>
                </div>

                <div class="ui-form-actions">
                    <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                        <x-ui.icon name="check" />
                        Save Expense
                    </button>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="Spend By Vehicle" description="GHS, for the current filters">
            <x-ui.bar-list label="Spend by vehicle" empty="No vehicle totals available." empty-icon="banknote"
                :items="collect($byVehicle)->map(fn ($row) => ['label' => $row->number_plate, 'value' => $row->total, 'display' => number_format($row->total, 2)])" />
        </x-ui.card>
    </div>

    <x-ui.card title="Expense Register" :padded="false">
        <div class="ui-toolbar" aria-label="Filter expenses" role="search">
            <select class="form-input" wire:model.live="vehicleFilter" aria-label="Vehicle">
                <option value="">All vehicles</option>
                @foreach ($vehicles as $vehicle)
                    <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }}</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="typeFilter" aria-label="Expense type">
                <option value="">All types</option>
                @foreach ($expenseTypes as $option)
                    <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
            <div class="date-range" role="group" aria-label="Date range">
                <input type="date" class="form-input" wire:model.live="dateFrom" aria-label="From date">
                <span class="date-range-sep" aria-hidden="true">to</span>
                <input type="date" class="form-input" wire:model.live="dateTo" aria-label="To date">
            </div>
        </div>

        <x-ui.table label="Expense register" pin-first>
            <x-slot:head>
                <tr>
                    <th>Date</th>
                    <th>Vehicle</th>
                    <th>Type</th>
                    <th class="num">Amount</th>
                    <th>Description</th>
                    <th>Recorded By</th>
                </tr>
            </x-slot:head>

            @forelse ($records as $record)
                <tr wire:key="expense-{{ $record->id }}">
                    <td class="nowrap">{{ $record->expense_date?->format('d M Y') }}</td>
                    <td class="mono nowrap">{{ $record->vehicle?->number_plate }}</td>
                    <td>{{ str($record->expense_type)->replace('_', ' ')->title() }}</td>
                    <td class="num nowrap">{{ $record->currency }} {{ number_format($record->amount, 2) }}</td>
                    <td class="cell-wrap">{{ $record->description }}</td>
                    <td class="nowrap">{{ $record->recorder?->full_name ?? $record->recorder?->email }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="6" icon="receipt" title="No expenses found." description="Widen the filters or log an expense above." />
            @endforelse

            @if ($records->hasPages())
                <x-slot:footer>
                    <div class="pager-end">{{ $records->links() }}</div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
