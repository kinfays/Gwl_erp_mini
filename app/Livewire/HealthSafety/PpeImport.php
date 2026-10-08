<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\Permission;
use App\Services\HealthSafety\PpeImportService;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Record what staff already hold, from Excel, as "already held" issues. Preview, then confirm; confirming reads the file
 * again on the server, so the preview the browser holds is Locked and only ever shown.
 */
class PpeImport extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithFileUploads;

    public ?TemporaryUploadedFile $file = null;

    /** @var array<string, mixed> */
    #[Locked]
    public array $preview = [];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.manage_ppe');
    }

    public function updatedFile(): void
    {
        $this->preview = [];
    }

    public function previewFile(PpeImportService $imports): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_ppe');

        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240']]);

        // The rows stay on the server (confirming reads the file again); the screen only needs the counts.
        $this->preview = Arr::except($imports->preview($this->file, $this->actor()), ['valid_rows']);

        $this->dispatch('toast', type: $this->preview['blocked'] ? 'error' : 'success', message: $this->preview['valid_count'].' of '.$this->preview['total_rows'].' rows are ready to import.');
    }

    public function runImport(PpeImportService $imports)
    {
        $this->guardHealthSafetyPermission('health_safety.manage_ppe');

        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240']]);

        $result = $imports->import($this->file, $this->actor());

        $this->dispatch('toast', type: 'success', message: $result['created'].' recorded, '.$result['skipped'].' skipped as already recorded.');

        return $this->redirectRoute('health_safety.ppe.issues', navigate: false);
    }

    public function clear(): void
    {
        $this->preview = [];
        $this->file = null;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.health_safety.ppe-import', [
            'headings' => PpeImportService::HEADINGS,
            'maxRows' => PpeImportService::MAX_ROWS,
        ]);
    }
}
