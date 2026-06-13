<?php

namespace App\Exports\Transport;

use App\Models\VehicleExpense;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class VehicleExpensesExport implements FromCollection, WithHeadings
{
    public function __construct(protected array $filters = []) {}

    public function collection()
    {
        return VehicleExpense::query()
            ->with(['vehicle', 'recorder'])
            ->when($this->filters['vehicle_id'] ?? null, fn ($query, $vehicleId) => $query->where('vehicle_id', $vehicleId))
            ->when($this->filters['expense_type'] ?? null, fn ($query, $type) => $query->where('expense_type', $type))
            ->when($this->filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '<=', $date))
            ->latest('expense_date')
            ->get()
            ->map(fn (VehicleExpense $expense): array => [
                'date' => $expense->expense_date?->toDateString(),
                'vehicle' => $expense->vehicle?->number_plate,
                'type' => $expense->expense_type,
                'amount' => $expense->amount,
                'currency' => $expense->currency,
                'description' => $expense->description,
                'recorded_by' => $expense->recorder?->full_name ?? $expense->recorder?->email,
            ]);
    }

    public function headings(): array
    {
        return [
            'Date',
            'Vehicle',
            'Type',
            'Amount',
            'Currency',
            'Description',
            'Recorded By',
        ];
    }
}
