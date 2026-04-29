<?php

namespace App\Exports\Visitors;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class VisitorsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(
        protected Collection $visitors
    ) {
    }

    public function collection(): Collection
    {
        return $this->visitors;
    }

    public function headings(): array
    {
        return [
            'Visitor Name',
            'Phone',
            'Visiting',
            'Department',
            'Purpose',
            'Check-in Time',
            'Check-out Time',
            'Checkout Code',
            'Status',
            'Checked Out By',
        ];
    }

    public function map($visitor): array
    {
        return [
            $visitor->visitor_name,
            $visitor->phone,
            $visitor->staff?->full_name,
            $visitor->staff?->department?->department_name,
            $visitor->purpose,
            $visitor->check_in_at?->format('Y-m-d H:i:s'),
            $visitor->check_out_at?->format('Y-m-d H:i:s'),
            $visitor->checkout_code,
            $visitor->status,
            $visitor->checked_out_by,
        ];
    }
}
