<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\Permission;
use App\Services\HealthSafety\EquipmentImportService;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Bring an existing register in from Excel: choose what the file holds, preview it, confirm. Confirming reads the file
 * again on the server (the preview is for the screen; nothing the browser holds is trusted), so the preview is Locked
 * and only ever shown.
 */
class EquipmentImport extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithFileUploads;

    public string $kind = EquipmentImportService::KIND_EXTINGUISHERS;

    public ?TemporaryUploadedFile $file = null;

    /** @var array<string, mixed> */
    #[Locked]
    public array $preview = [];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.manage_equipment');
    }

    public function updatedKind(): void
    {
        abort_unless(in_array($this->kind, [EquipmentImportService::KIND_EXTINGUISHERS, EquipmentImportService::KIND_KITS], true), 422);

        $this->clear();
    }

    public function updatedFile(): void
    {
        $this->preview = [];
    }

    public function previewFile(EquipmentImportService $imports): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_equipment');

        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240']]);

        // The rows themselves stay on the server (confirming reads the file again); the screen only needs the counts.
        $this->preview = Arr::except($imports->preview($this->kind, $this->file, $this->actor()), ['valid_rows', 'preview_rows']);

        $this->dispatch(
            'toast',
            type: $this->preview['blocked'] ? 'error' : 'success',
            message: $this->preview['valid_count'].' of '.$this->preview['total_rows'].' rows are ready to import.'
        );
    }

    public function runImport(EquipmentImportService $imports)
    {
        $this->guardHealthSafetyPermission('health_safety.manage_equipment');

        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240']]);

        $result = $imports->import($this->kind, $this->file, $this->actor());

        $this->dispatch('toast', type: 'success', message: $result['created'].' imported, '.$result['skipped'].' skipped as already existing.');

        return $this->redirectRoute($this->kind === EquipmentImportService::KIND_KITS ? 'health_safety.kits.index' : 'health_safety.extinguishers.index', navigate: false);
    }

    public function clear(): void
    {
        $this->preview = [];
        $this->file = null;
        $this->resetErrorBag();
    }

    public function render(EquipmentImportService $imports)
    {
        return view('livewire.health_safety.equipment-import', [
            'headings' => $imports->headings($this->kind),
            'maxRows' => EquipmentImportService::MAX_ROWS,
        ]);
    }
}
