<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Services\GWater\GwaterImportService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Bring past records in from one workbook: preview (everything is checked and rolled back), then confirm. Confirming reads the
 * file again on the server, so the preview held here is Locked and only ever shown. The importer's own location is used (for
 * super_admin, the one chosen above); an import never creates one.
 */
class Import extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;
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
        $this->enforceGwater();
        $this->guard('gwater.record');
    }

    public function updatedFile(): void
    {
        $this->preview = [];
        $this->outcome = [];
    }

    public function previewFile(GwaterImportService $imports): void
    {
        $this->guard('gwater.record');
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
        $this->outcome = [];

        $this->preview = $this->summarise($this->run(fn () => $imports->preview($this->file, $this->actor(), $this->recordingDistrict()), 'file'));
        $this->dispatch('toast', type: $this->preview['blocked'] || $this->preview['error_count'] ? 'warning' : 'success', message: $this->preview['valid_count'].' of '.$this->preview['total_rows'].' rows are ready to import.');
    }

    public function runImport(GwaterImportService $imports): void
    {
        $this->import($imports, false);
    }

    /** Keeps the rows that pass and leaves the failing ones out (listed). */
    public function runImportValidOnly(GwaterImportService $imports): void
    {
        $this->import($imports, true);
    }

    protected function import(GwaterImportService $imports, bool $validOnly): void
    {
        $this->guard('gwater.record');
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);

        $this->outcome = $this->summarise($this->run(fn () => $imports->import($this->file, $this->actor(), $this->recordingDistrict(), $validOnly), 'file'));
        $this->preview = [];

        $this->dispatch(
            'toast',
            type: $this->outcome['committed'] ? 'success' : 'warning',
            message: $this->outcome['committed'] ? $this->outcome['imported'].' rows imported.' : 'Nothing was imported. Fix the rows listed, or import the valid rows only.'
        );
    }

    public function clear(): void
    {
        $this->preview = [];
        $this->outcome = [];
        $this->file = null;
        $this->resetErrorBag();
    }

    /** Keeps what the screen shows: the counts and at most 200 problems. @param array<string, mixed> $result */
    protected function summarise(array $result): array
    {
        $result['more_errors'] = max(0, count($result['errors']) - 200);
        $result['errors'] = array_slice($result['errors'], 0, 200);

        return $result;
    }

    public function render(GwaterImportService $imports)
    {
        return view('livewire.gwater.import', [
            'sheets' => GwaterImportService::SHEETS,
            'headings' => collect(GwaterImportService::SHEETS)->map(fn ($definition, $key) => $imports->headings($key)),
            'maxRows' => (int) config('gwl.gwater_import_max_rows', 2000),
        ] + $this->locationView());
    }
}
