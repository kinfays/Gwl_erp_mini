<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsPpeStockMovement;
use App\Models\HsPpeType;
use App\Models\HsSite;
use App\Models\Permission;
use App\Services\HealthSafety\PpeStockService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * PPE stock: what each store holds (per type and size, with low-stock flags from the reorder levels) and the ledger behind
 * it. Receiving, adjusting, writing off and transferring are posted here by whoever holds manage_ppe; issuing to staff
 * has its own screen. Viewing needs view_equipment, within the user's part of the register.
 */
class PpeStock extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithPagination;

    #[Url(except: '')]
    public string $storeId = '';

    #[Url(except: '')]
    public string $typeId = '';

    /** Only the store/type rows that are at or below their reorder level (the overview links here with ?low=1). */
    #[Url(except: false)]
    public bool $low = false;

    /** 'receive', 'adjust', 'write_off', 'transfer' or '' (which form is open). */
    #[Locked]
    public string $panel = '';

    public ?int $panelStoreId = null;

    public ?int $panelTypeId = null;

    public string $panelSize = '';

    public ?int $toStoreId = null;

    public string $quantity = '';

    public string $reference = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.view_equipment');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['storeId', 'typeId', 'low'], true)) {
            $this->resetPage();
        }
    }

    public function updatedPanelTypeId(): void
    {
        $this->panelSize = '';
    }

    public function openPanel(string $kind, ?int $storeId = null, ?int $typeId = null): void
    {
        abort_unless(in_array($kind, ['receive', 'adjust', 'write_off', 'transfer'], true), 422);
        $this->guardHealthSafetyPermission('health_safety.manage_ppe');

        $this->resetPanel();
        $this->panel = $kind;
        $this->panelStoreId = $storeId;
        $this->panelTypeId = $typeId;

        // An officer normally has one store (the regional office): choose it for them.
        if ($storeId === null) {
            $stores = $this->equipmentScope()->ppeStores($this->actor())->limit(2)->pluck('id');
            $this->panelStoreId = $stores->count() === 1 ? (int) $stores->first() : null;
        }
    }

    public function closePanel(): void
    {
        $this->resetPanel();
    }

    public function save(PpeStockService $stock): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_ppe');
        $this->resetErrorBag();

        $this->validate([
            'panelStoreId' => ['required', 'integer'],
            'panelTypeId' => ['required', 'integer'],
            'quantity' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'panelStoreId.required' => 'Choose the store.',
            'panelTypeId.required' => 'Choose the PPE type.',
            'quantity.required' => 'Enter a quantity.',
            'quantity.integer' => 'Enter a whole number.',
        ]);

        $store = $this->storeOf($this->panelStoreId);
        $type = HsPpeType::query()->findOrFail($this->panelTypeId);
        $size = $this->panelSize === '' ? null : $this->panelSize;
        $quantity = (int) $this->quantity;
        $actor = $this->actor();

        match ($this->panel) {
            'receive' => $stock->receive($actor, $store, $type, $size, $quantity, $this->reference, $this->reason),
            'adjust' => $stock->adjust($actor, $store, $type, $size, $quantity, $this->reason),
            'write_off' => $stock->writeOff($actor, $store, $type, $size, $quantity, $this->reason),
            'transfer' => $stock->transfer($actor, $store, $this->storeOf($this->toStoreId), $type, $size, $quantity, $this->reason),
            default => abort(422),
        };

        $this->resetPanel();
        $this->dispatch('toast', type: 'success', message: 'Stock updated.');
    }

    /** A site, checked against the actor's scope: a store id from the browser is never trusted. */
    protected function storeOf(?int $id): HsSite
    {
        $store = HsSite::query()->find($id);

        abort_unless($store && $this->equipmentScope()->contains($this->actor(), $store), 403, 'That store is not available to you.');

        return $store;
    }

    protected function resetPanel(): void
    {
        $this->panel = '';
        $this->panelStoreId = null;
        $this->panelTypeId = null;
        $this->panelSize = '';
        $this->toStoreId = null;
        $this->quantity = '';
        $this->reference = '';
        $this->reason = '';
        $this->resetErrorBag();
    }

    /** @return array<string, mixed> the filters as the export route takes them */
    public function filters(): array
    {
        return array_filter(['store_id' => $this->storeId, 'type_id' => $this->typeId, 'low' => $this->low ? '1' : ''], fn ($value) => $value !== '');
    }

    public function render(PpeStockService $stock)
    {
        $actor = $this->actor();
        $scope = $this->equipmentScope();

        $matrix = $stock->matrix($actor, $this->storeId !== '' ? (int) $this->storeId : null, $this->typeId !== '' ? (int) $this->typeId : null)
            ->when($this->low, fn ($rows) => $rows->filter(fn (array $row) => $row['low']))
            ->groupBy(fn (array $row) => $row['store']->id);

        $storeIds = $scope->ppeStores($actor)->pluck('id');

        $movements = HsPpeStockMovement::query()
            ->with(['site:id,name', 'type:id,name', 'creator:id,full_name', 'issue.employee:id,full_name,staff_id'])
            ->whereIn('site_id', $this->storeId !== '' ? $storeIds->intersect([(int) $this->storeId]) : $storeIds)
            ->when($this->typeId !== '', fn ($query) => $query->where('ppe_type_id', (int) $this->typeId))
            ->orderByDesc('id')
            ->paginate(15);

        $selectedType = $this->panelTypeId ? HsPpeType::query()->find($this->panelTypeId) : null;

        return view('livewire.health_safety.ppe-stock', [
            'matrix' => $matrix,
            'movements' => $movements,
            'stores' => $scope->ppeStores($actor)->orderBy('name')->get(['id', 'name']),
            'types' => HsPpeType::query()->orderBy('name')->get(['id', 'name', 'is_active']),
            'selectedType' => $selectedType,
            'canManage' => $this->actorCan('health_safety.manage_ppe'),
            'canExport' => $this->actorCan('health_safety.export_reports'),
            'exportFilters' => $this->filters(),
            'movementTypes' => HsPpeStockMovement::TYPES,
            'lowCount' => $stock->lowStock($actor)->count(),
        ]);
    }
}
