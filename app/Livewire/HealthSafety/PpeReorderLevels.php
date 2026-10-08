<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsPpeReorderLevel;
use App\Models\HsPpeType;
use App\Models\Permission;
use App\Services\HealthSafety\PpeSetupService;
use App\Services\HealthSafety\PpeStockService;
use Livewire\Component;

/**
 * The stock level at or below which a store's PPE is flagged low, per store and type (the total across sizes). A type
 * with no level is never flagged.
 */
class PpeReorderLevels extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;

    public string $storeId = '';

    /** ppe_type_id => level (empty: none). @var array<int, int|string|null> */
    public array $levels = [];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');

        $this->storeId = (string) ($this->equipmentScope()->ppeStores($this->actor())->orderBy('name')->value('id') ?? '');
        $this->loadLevels();
    }

    public function updatedStoreId(): void
    {
        $this->loadLevels();
    }

    public function save(PpeSetupService $setup): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');
        $this->resetErrorBag();

        $store = $this->equipmentScope()->ppeStores($this->actor())->find($this->storeId);
        abort_unless($store, 403, 'That store is not available to you.');

        $changed = $setup->saveReorderLevels(
            $this->actor(),
            collect($this->levels)->map(fn ($level, $typeId) => ['site_id' => $store->id, 'ppe_type_id' => $typeId, 'level' => $level])->values()->all()
        );

        $this->loadLevels();
        $this->dispatch('toast', type: 'success', message: $changed === 0 ? 'Nothing changed.' : $changed.' reorder level'.($changed === 1 ? '' : 's').' saved.');
    }

    protected function loadLevels(): void
    {
        $current = HsPpeReorderLevel::query()->where('site_id', (int) $this->storeId)->pluck('level', 'ppe_type_id');

        $this->levels = HsPpeType::query()->active()->pluck('id')->mapWithKeys(fn ($id) => [$id => $current[$id] ?? ''])->all();
        $this->resetErrorBag();
    }

    public function render(PpeStockService $stock)
    {
        $actor = $this->actor();
        $stores = $this->equipmentScope()->ppeStores($actor)->orderBy('name')->get(['id', 'name']);
        $store = $stores->firstWhere('id', (int) $this->storeId);

        return view('livewire.health_safety.ppe-reorder-levels', [
            'stores' => $stores,
            'types' => HsPpeType::query()->active()->orderBy('name')->get(['id', 'name']),
            'totals' => $store ? HsPpeType::query()->active()->get()->mapWithKeys(fn ($type) => [$type->id => $stock->total($store, $type)]) : collect(),
        ]);
    }
}
