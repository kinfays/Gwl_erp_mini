<?php

namespace App\Exports\Leave;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class HrDashboardExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        protected Collection $requests
    ) {}

    public function collection(): Collection
    {
        return $this->requests;
    }

    public function headings(): array
    {
        return [
            'Employee',
            'Gender',
            'Department',
            'Region',
            'Leave Type',
            'Days',
            'Start Date',
            'End Date',
        ];
    }

    public function map($req): array
    {
        return [
            $req->requester->full_name,
            $req->requester->gender,
            $req->department?->name,
            $req->region?->name ?? 'Head Office',
            $req->leave_type->value,
            $req->total_days_applied,
            $req->start_date->toDateString(),
            $req->end_date->toDateString(),
        ];
    }
}