<?php

namespace App\Exports\Leave;

use App\Models\LeaveRequest;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LeaveRequestsExport implements FromCollection, WithHeadings, WithMapping
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
            'Department',
            'Region',
            'Leave Type',
            'Start Date',
            'End Date',
            'Days',
            'Status',
            'Manager',
            'Approved By',
            'Date Submitted',
        ];
    }

    public function map($req): array
    {
        return [
            $req->requester->full_name,
            $req->department?->name,
            $req->region?->name ?? 'Head Office',
            $req->leave_type->value,
            $req->start_date->toDateString(),
            $req->end_date->toDateString(),
            $req->total_days_applied,
            $req->leave_status->value,
            $req->manager?->full_name,
            $req->approver?->full_name,
            $req->created_at->toDateString(),
        ];
    }
}