<?php

namespace App\Livewire\Concerns;

use App\Support\ListColumns;
use Livewire\Attributes\Url;

/**
 * Lets the user choose which columns of a list are shown. The component declares its columns in columnManifest()
 * (see ListColumns for the shape); the choice lives in the bookmarkable ?cols= parameter and is parsed by ListColumns,
 * the same code the export controllers use. Pass columnViewData() to the view and drop <x-ui.column-picker /> in the toolbar.
 */
trait HasSelectableColumns
{
    /** The selected column keys, comma-joined; empty means the manifest's defaults. */
    #[Url(as: 'cols', except: '')]
    public string $colsParam = '';

    /** @return array<string, array<string, mixed>> ordered map of key => ['label', 'default', 'locked', 'export'] */
    abstract protected function columnManifest(): array;

    /** @return array<int, string> */
    public function visibleColumns(): array
    {
        return ListColumns::resolve($this->columnManifest(), $this->colsParam);
    }

    public function isColumnVisible(string $key): bool
    {
        return in_array($key, $this->visibleColumns(), true);
    }

    public function toggleColumn(string $key): void
    {
        $manifest = $this->columnManifest();

        if (! array_key_exists($key, $manifest) || ($manifest[$key]['locked'] ?? false)) {
            return;
        }

        $visible = $this->visibleColumns();

        $selection = in_array($key, $visible, true)
            ? array_values(array_diff($visible, [$key]))
            : [...$visible, $key];

        // Written back as the effective selection (not a diff), so the URL always says what is on screen.
        $this->colsParam = implode(',', ListColumns::resolve($manifest, implode(',', $selection)));
    }

    public function resetColumns(): void
    {
        $this->colsParam = '';
    }

    /** Every column of the manifest (credentials included: it stays masked and permission-gated on screen). */
    public function showAllColumns(): void
    {
        $this->colsParam = implode(',', array_keys($this->columnManifest()));
    }

    /** Which picker option the current selection is: default | all | custom. */
    protected function columnMode(): string
    {
        if ($this->colsParam === '') {
            return 'default';
        }

        return count($this->visibleColumns()) === count($this->columnManifest()) ? 'all' : 'custom';
    }

    /** @return array{columnManifest: array<string, array<string, mixed>>, visibleColumns: array<int, string>} */
    protected function columnViewData(): array
    {
        return [
            'columnManifest' => $this->columnManifest(),
            'visibleColumns' => $this->visibleColumns(),
            'columnMode' => $this->columnMode(),
        ];
    }
}
