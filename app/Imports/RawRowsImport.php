<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class RawRowsImport implements ToCollection
{
    public Collection $rows;

    private bool $loaded = false;

    /**
     * By default every sheet of the workbook calls collection() in turn and the LAST one wins. $firstSheetOnly keeps the
     * first sheet instead, for templates that carry extra sheets after the one to fill in (allowed values, instructions).
     */
    public function __construct(protected bool $firstSheetOnly = false)
    {
        $this->rows = collect();
    }

    public function collection(Collection $collection): void
    {
        if ($this->firstSheetOnly && $this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->rows = $collection;
    }
}
