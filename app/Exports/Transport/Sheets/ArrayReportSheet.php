<?php

namespace App\Exports\Transport\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ArrayReportSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        protected string $title,
        protected array $headings,
        protected array $rows
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }
}
