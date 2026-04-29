<?php

namespace App\Exports\Staff;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class EmployeesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(
        protected Collection $employees
    ) {
    }

    public function collection(): Collection
    {
        return $this->employees;
    }

    public function headings(): array
    {
        return [
            'Staff ID',
            'Full Name',
            'Email',
            'Department',
            'Category',
            'District',
            'Region',
            'Location Type',
            'Annual Leave Balance',
            'Status',
        ];
    }

    public function map($employee): array
    {
        $annualBalance = $employee->leaveBalances->first()?->remaining_days;

        if ($annualBalance === null && $employee->is_active) {
            $annualBalance = $employee->annual_leave_days;
        }

        $status = ! $employee->is_active
            ? 'Inactive'
            : ((int) ($employee->on_leave_count ?? 0) > 0 ? 'On Leave' : 'Active');

        return [
            $employee->staff_id,
            $employee->full_name,
            $employee->email,
            $employee->department?->department_name,
            $employee->category,
            $employee->district?->district_name,
            $employee->region?->region_name,
            $employee->location_type,
            $annualBalance ?? '-',
            $status,
        ];
    }
}
