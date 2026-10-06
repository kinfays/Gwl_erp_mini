<?php

namespace App\Imports\Commercial;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Reads the sheets of a workbook that are worth reading as raw grids of cell values (RawRowsImport only sees the first
 * one). The report exports are not tables: a sheet is a filter block, labels and a few blocks of numbers, so the
 * importers get the cells exactly as laid out and find what they need by label.
 *
 * The caller passes the sheet names to skip. The real exports begin with a "Document map" sheet whose used range runs
 * to column XFC; reading it cell by cell exhausts memory, so it must never be loaded (WithMultipleSheets makes the
 * reader load only the sheets named here).
 *
 * Formulas are calculated so a cell showing a value never arrives as "=B4/C4"; a formula that errors in Excel
 * (#VALUE! on a zero-billing route) arrives as that error text.
 */
class SheetGridsImport implements Import, WithMultipleSheets
{
    /** @var array<int, array{name: string, rows: array<int, array<int, mixed>>}> keyed by sheet position */
    public array $sheets = [];

    /**
     * @param  list<string>  $sheetNames  every sheet of the workbook, in order
     * @param  callable(string): bool  $skip  true for a sheet that must not be loaded
     */
    public function __construct(protected array $sheetNames, protected $skip)
    {
    }

    /**
     * Keyed by position, not by name: a sheet called "15071" would otherwise become an integer key and be read as a
     * position.
     */
    public function sheets(): array
    {
        $imports = [];

        foreach ($this->sheetNames as $position => $name) {
            if (($this->skip)($name)) {
                continue;
            }

            $imports[$position] = new class($this, $position, $name) implements ToArray, WithCalculatedFormulas
            {
                public function __construct(private SheetGridsImport $parent, private int $position, private string $name)
                {
                }

                public function array(array $array): void
                {
                    $this->parent->sheets[$this->position] = [
                        'name' => $this->name,
                        'rows' => array_values(array_map(fn ($row) => array_values((array) $row), $array)),
                    ];
                }
            };
        }

        return $imports;
    }
}
