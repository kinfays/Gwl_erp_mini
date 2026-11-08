<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\Permission;
use App\Services\Finance\PvCatalogService;
use Livewire\Component;

/**
 * Finance > Settings > Categories and types: the category / type list of ONE location, for whoever holds
 * finance.manage_categories (the Finance manager, for their own location; super_admin picks a location, or edits the system
 * defaults). Everything is read and changed through PvCatalogService. No other screen, Audit included, has controls for it.
 */
class Settings extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;

    public string $newCategory = '';

    /** @var array<int, string> new type name per category id */
    public array $newType = [];

    public ?int $renamingCategory = null;

    public ?int $renamingType = null;

    public string $renameValue = '';

    /** @var array<int, string> the category a location-owned type is being moved to, per type id */
    public array $moveTo = [];

    /** super_admin only: work on the system defaults instead of a location. */
    public bool $defaults = false;

    public function mount(): void
    {
        $this->enforceFinance(Permission::MODULE_FINANCE);
        $this->guard('finance.manage_categories');
    }

    public function updatedDefaults(): void
    {
        $this->cancelRename();
    }

    public function updatedLocationId(): void
    {
        $this->cancelRename();
    }

    public function addCategory(PvCatalogService $catalogue): void
    {
        $this->resetErrorBag();
        $this->run(fn () => $catalogue->createCategory($this->actor(), $this->newCategory, $this->recordingDistrict(), $this->defaults), 'catalogue');
        $this->newCategory = '';
    }

    public function addType(PvCatalogService $catalogue, int $categoryId): void
    {
        $this->resetErrorBag();
        $this->run(fn () => $catalogue->createType($this->actor(), $categoryId, $this->newType[$categoryId] ?? '', $this->recordingDistrict(), $this->defaults), 'catalogue');
        $this->newType[$categoryId] = '';
    }

    // The browser sends only the id: the name being edited is read here, from the list the person is working on (never trusted
    // from the page; an id outside it is a 404).
    public function startRenameCategory(PvCatalogService $catalogue, int $id): void
    {
        $this->guard('finance.manage_categories');
        $category = $this->visibleCategories($catalogue)->firstWhere('id', $id);
        abort_unless($category, 404);

        $this->renamingCategory = $id;
        $this->renamingType = null;
        $this->renameValue = $category->effective_name;
        $this->resetErrorBag();
    }

    public function startRenameType(PvCatalogService $catalogue, int $id): void
    {
        $this->guard('finance.manage_categories');
        $type = $this->visibleCategories($catalogue)->flatMap->types->firstWhere('id', $id);
        abort_unless($type, 404);

        $this->renamingType = $id;
        $this->renamingCategory = null;
        $this->renameValue = $type->effective_name;
        $this->resetErrorBag();
    }

    /** The list being worked on: the system defaults (super_admin's switch), or the location. */
    protected function visibleCategories(PvCatalogService $catalogue)
    {
        $defaults = $this->defaults && $this->isSuperAdmin();
        $location = $defaults ? null : $this->workingLocation();

        return $defaults || $location ? $catalogue->effective($location) : collect();
    }

    public function saveRename(PvCatalogService $catalogue): void
    {
        $this->resetErrorBag();
        $this->run(function () use ($catalogue) {
            $this->renamingCategory
                ? $catalogue->renameCategory($this->actor(), $this->renamingCategory, $this->renameValue, $this->recordingDistrict(), $this->defaults)
                : $catalogue->renameType($this->actor(), (int) $this->renamingType, $this->renameValue, $this->recordingDistrict(), $this->defaults);
        }, 'catalogue');
        $this->cancelRename();
    }

    public function cancelRename(): void
    {
        $this->renamingCategory = null;
        $this->renamingType = null;
        $this->renameValue = '';
    }

    public function toggleCategory(PvCatalogService $catalogue, int $id, bool $active): void
    {
        $this->resetErrorBag();
        $this->run(fn () => $catalogue->setCategoryActive($this->actor(), $id, $active, $this->recordingDistrict(), $this->defaults), 'catalogue');
    }

    public function toggleType(PvCatalogService $catalogue, int $id, bool $active): void
    {
        $this->resetErrorBag();
        $this->run(fn () => $catalogue->setTypeActive($this->actor(), $id, $active, $this->recordingDistrict(), $this->defaults), 'catalogue');
    }

    public function moveType(PvCatalogService $catalogue, int $typeId): void
    {
        $this->resetErrorBag();
        $this->run(fn () => $catalogue->moveType($this->actor(), $typeId, (int) ($this->moveTo[$typeId] ?? 0), $this->recordingDistrict(), $this->defaults), 'catalogue');
        unset($this->moveTo[$typeId]);
    }

    public function render(PvCatalogService $catalogue)
    {
        // Defaults mode is super_admin's alone (the service refuses anyone else); everyone else works in their own location.
        return view('livewire.finance.settings', [
            'categories' => $this->visibleCategories($catalogue),
            'editingDefaults' => $this->defaults && $this->isSuperAdmin(),
        ] + $this->locationView());
    }
}
