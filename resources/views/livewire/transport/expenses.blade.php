<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Expenses</h2>
            <p>Vehicle costs, filters, totals, and export.</p>
        </div>
        <div class="ph-right">
            <a
                class="btn btn-secondary"
                href="{{ route('transport.expenses.export', ['vehicle_id' => $vehicleFilter ?: null, 'expense_type' => $typeFilter ?: null, 'date_from' => $dateFrom ?: null, 'date_to' => $dateTo ?: null]) }}"
            >Export Excel</a>
        </div>
    </div>

    <div class="stats" style="margin-top:14px">
        <div class="stat">
            <div class="stat-lbl">Filtered Total</div>
            <div class="stat-val">{{ number_format($total, 2) }}</div>
            <div class="stat-sub">GHS</div>
        </div>
        @foreach ($byType->take(3) as $row)
            <div class="stat">
                <div class="stat-lbl">{{ str($row->expense_type)->replace('_', ' ')->title() }}</div>
                <div class="stat-val">{{ number_format($row->total, 2) }}</div>
            </div>
        @endforeach
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Log Expense</span>
        </div>
        <div style="padding:14px">
            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Vehicle</label>
                    <select class="form-input" wire:model="form.vehicle_id">
                        <option value="">Select vehicle</option>
                        @foreach ($vehicles as $vehicle)
                            <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }} - {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                        @endforeach
                    </select>
                    @error('form.vehicle_id') <span class="form-error">{{ $message }}</span> @enderror
                </div>
                <div class="form-field">
                    <label class="form-label">Type</label>
                    <select class="form-input" wire:model="form.expense_type">
                        @foreach ($expenseTypes as $option)
                            <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Amount</label>
                    <input type="number" step="0.01" class="form-input" wire:model.defer="form.amount">
                    @error('form.amount') <span class="form-error">{{ $message }}</span> @enderror
                </div>
                <div class="form-field">
                    <label class="form-label">Currency</label>
                    <input maxlength="3" class="form-input" wire:model.defer="form.currency">
                    @error('form.currency') <span class="form-error">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Expense Date</label>
                    <input type="date" class="form-input" wire:model.defer="form.expense_date">
                    @error('form.expense_date') <span class="form-error">{{ $message }}</span> @enderror
                </div>
                <div class="form-field">
                    <label class="form-label">Receipt Photo</label>
                    <input type="file" class="form-input" wire:model="receipt">
                    @error('receipt') <span class="form-error">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label class="form-label">Description</label>
                    <textarea rows="3" class="form-input" wire:model.defer="form.description"></textarea>
                </div>
                <div class="form-field" style="justify-content:end">
                    <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Save Expense</button>
                </div>
            </div>
        </div>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Expense Register</span>
            <div class="ph-right">
                <select class="form-input" wire:model.live="vehicleFilter">
                    <option value="">All vehicles</option>
                    @foreach ($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }}</option>
                    @endforeach
                </select>
                <select class="form-input" wire:model.live="typeFilter">
                    <option value="">All types</option>
                    @foreach ($expenseTypes as $option)
                        <option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
                <input type="date" class="form-input" wire:model.live="dateFrom">
                <input type="date" class="form-input" wire:model.live="dateTo">
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Vehicle</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Description</th>
                    <th>Recorded By</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td>{{ $record->expense_date?->format('d M Y') }}</td>
                        <td>{{ $record->vehicle?->number_plate }}</td>
                        <td>{{ str($record->expense_type)->replace('_', ' ')->title() }}</td>
                        <td>{{ $record->currency }} {{ number_format($record->amount, 2) }}</td>
                        <td>{{ $record->description }}</td>
                        <td>{{ $record->recorder?->full_name ?? $record->recorder?->email }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-state">No expenses found.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px 14px">{{ $records->links() }}</div>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Spend By Vehicle</span>
        </div>
        <table>
            <thead><tr><th>Vehicle</th><th>Total</th></tr></thead>
            <tbody>
                @forelse ($byVehicle as $row)
                    <tr><td>{{ $row->number_plate }}</td><td>{{ number_format($row->total, 2) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="empty-state">No vehicle totals available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
