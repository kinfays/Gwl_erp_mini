<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\Permission;
use App\Services\Finance\SupplierImportService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Bring suppliers in from one workbook: preview (everything is checked and rolled back), then confirm. Confirming reads the file
 * again on the server, so the preview held here is Locked and only ever shown. Create-only: a supplier already listed is skipped.
 */
class SupplierImport extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;
    use WithFileUploads;

    public ?TemporaryUploadedFile $file = null;

    /** @var array<string, mixed> */
    #[Locked]
    public array $preview = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $outcome = [];

    public function mount(): void
    {
        $this->enforceFinance(Permission::MODULE_FINANCE);
        $this->guard('finance.import_suppliers');
        $this->guard('finance.manage_suppliers');
    }

    public function updatedFile(): void
    {
        $this->preview = [];
        $this->outcome = [];
    }

    public function previewFile(SupplierImportService $imports): void
    {
        $this->guard('finance.import_suppliers');
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
        $this->outcome = [];

        $this->preview = $this->summarise($imports->preview($this->file, $this->actor()));
        $this->dispatch('toast', type: $this->preview['blocked'] || $this->preview['error_count'] ? 'warning' : 'success', message: $this->preview['valid_count'].' of '.$this->preview['total_rows'].' rows are new suppliers ready to import.');
    }

    public function runImport(SupplierImportService $imports): void
    {
        $this->import($imports, false);
    }

    /** Keeps the rows that pass and leaves the failing ones out (listed). */
    public function runImportValidOnly(SupplierImportService $imports): void
    {
        $this->import($imports, true);
    }

    protected function import(SupplierImportService $imports, bool $validOnly): void
    {
        $this->guard('finance.import_suppliers');
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);

        $this->outcome = $this->summarise($imports->import($this->file, $this->actor(), $validOnly));
        $this->preview = [];

        $this->dispatch(
            'toast',
            type: $this->outcome['committed'] ? 'success' : 'warning',
            message: $this->outcome['committed'] ? $this->outcome['imported'].' suppliers imported.' : 'Nothing was imported. Fix the rows listed, or import the valid rows only.'
        );
    }

    public function clear(): void
    {
        $this->preview = [];
        $this->outcome = [];
        $this->file = null;
        $this->resetErrorBag();
    }

    /** Keeps what the screen shows: the counts and at most 200 of each list. @param array<string, mixed> $result */
    protected function summarise(array $result): array
    {
        foreach (['errors', 'warnings', 'skipped'] as $list) {
            $result['more_'.$list] = max(0, count($result[$list]) - 200);
            $result[$list] = array_slice($result[$list], 0, 200);
        }

        return $result;
    }

    public function render(SupplierImportService $imports)
    {
        return view('livewire.finance.supplier-import', [
            'headings' => SupplierImportService::HEADINGS,
            'maxRows' => $imports->maxRows(),
        ]);
    }
}
