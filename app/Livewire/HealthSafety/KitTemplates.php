<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsFirstAidItemTemplate;
use App\Models\HsFirstAidKit;
use App\Models\Permission;
use App\Services\HealthSafety\FirstAidKitService;
use Livewire\Component;

/**
 * What a new kit of each type starts with. A kit copies its template when it is created, so saving a template never
 * changes a kit that already exists. No template ships with the system; "Load suggested starter items" only fills the
 * editable list below, and nothing is saved until the officer saves it. The contents must be confirmed with EHS or a
 * first aid trainer.
 */
class KitTemplates extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;

    public string $kitType = HsFirstAidKit::TYPE_MEDIUM;

    /** @var list<array{item_name: string, required_qty: int|string, has_expiry: bool}> */
    public array $rows = [];

    public bool $starterLoaded = false;

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');

        $this->loadType();
    }

    public function updatedKitType(): void
    {
        $this->loadType();
    }

    public function addRow(): void
    {
        $this->rows[] = ['item_name' => '', 'required_qty' => 1, 'has_expiry' => false];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    /** Fill the list (not the database) with generic starter items, to be reviewed and edited before saving. */
    public function loadStarterItems(): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');

        $this->rows = collect(HsFirstAidItemTemplate::STARTER_ITEMS)
            ->map(fn (array $item) => ['item_name' => $item[0], 'required_qty' => $item[1], 'has_expiry' => $item[2]])
            ->all();
        $this->starterLoaded = true;
    }

    public function save(FirstAidKitService $service): void
    {
        $this->resetErrorBag();

        $count = $service->saveTemplates($this->actor(), $this->kitType, $this->rows);

        $this->loadType();
        $this->dispatch('toast', type: 'success', message: $count.' item'.($count === 1 ? '' : 's').' saved for '.strtolower(HsFirstAidKit::TYPES[$this->kitType]).' kits. Existing kits are unchanged.');
    }

    protected function loadType(): void
    {
        if (! array_key_exists($this->kitType, HsFirstAidKit::TYPES)) {
            $this->kitType = HsFirstAidKit::TYPE_MEDIUM;
        }

        $this->rows = HsFirstAidItemTemplate::query()
            ->where('kit_type', $this->kitType)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($template) => ['item_name' => $template->item_name, 'required_qty' => $template->required_qty, 'has_expiry' => $template->has_expiry])
            ->all();
        $this->starterLoaded = false;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.health_safety.kit-templates', [
            'types' => HsFirstAidKit::TYPES,
            'counts' => HsFirstAidItemTemplate::query()->selectRaw('kit_type, count(*) as total')->groupBy('kit_type')->pluck('total', 'kit_type'),
        ]);
    }
}
