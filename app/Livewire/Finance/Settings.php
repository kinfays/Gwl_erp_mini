<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\FinPvCategory;
use App\Models\Permission;
use App\Services\Finance\PvCatalogService;
use Livewire\Component;

/**
 * The category / type catalogue: shared company-wide, changed only through PvCatalogService (finance.manage_categories).
 * Without that permission it is read-only (this is also what Audit sees, from the Audit module).
 */
class Settings extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;

    /** 'finance' or 'audit': which module's door the user came through (its module access and view permission apply). */
    public string $module = 'finance';

    public string $newCategory = '';

    /** @var array<int, string> new type name per category id */
    public array $newType = [];

    public ?int $renamingCategory = null;

    public ?int $renamingType = null;

    public string $renameValue = '';

    public function mount(string $module = 'finance'): void
    {
        $this->module = $module === 'audit' ? 'audit' : 'finance';
        $this->enforceFinance($this->module === 'audit' ? Permission::MODULE_AUDIT : Permission::MODULE_FINANCE);
        $this->guard($this->module.'.view');
    }

    public function addCategory(PvCatalogService $catalogue): void
    {
        $this->run(fn () => $catalogue->createCategory($this->actor(), $this->newCategory), 'catalogue');
        $this->newCategory = '';
    }

    public function addType(PvCatalogService $catalogue, int $categoryId): void
    {
        $this->run(fn () => $catalogue->createType($this->actor(), $categoryId, $this->newType[$categoryId] ?? ''), 'catalogue');
        $this->newType[$categoryId] = '';
    }

    public function startRenameCategory(int $id, string $current): void
    {
        $this->guard('finance.manage_categories');
        $this->renamingCategory = $id;
        $this->renamingType = null;
        $this->renameValue = $current;
        $this->resetErrorBag();
    }

    public function startRenameType(int $id, string $current): void
    {
        $this->guard('finance.manage_categories');
        $this->renamingType = $id;
        $this->renamingCategory = null;
        $this->renameValue = $current;
        $this->resetErrorBag();
    }

    public function saveRename(PvCatalogService $catalogue): void
    {
        $this->run(function () use ($catalogue) {
            $this->renamingCategory
                ? $catalogue->renameCategory($this->actor(), $this->renamingCategory, $this->renameValue)
                : $catalogue->renameType($this->actor(), (int) $this->renamingType, $this->renameValue);
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
        $this->run(fn () => $catalogue->setCategoryActive($this->actor(), $id, $active), 'catalogue');
    }

    public function toggleType(PvCatalogService $catalogue, int $id, bool $active): void
    {
        $this->run(fn () => $catalogue->setTypeActive($this->actor(), $id, $active), 'catalogue');
    }

    public function render()
    {
        return view('livewire.finance.settings', [
            'categories' => FinPvCategory::query()->with(['types' => fn ($query) => $query->orderBy('sort_order')->orderBy('name')])->orderBy('sort_order')->orderBy('name')->get(),
            'canManage' => $this->can('finance.manage_categories'),
        ]);
    }
}
