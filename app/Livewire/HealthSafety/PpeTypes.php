<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsPpeType;
use App\Models\Permission;
use App\Services\HealthSafety\PpeSetupService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The kinds of PPE: name, category, sizes, service life in months, whether it carries its own expiry date. Nothing is
 * pre-loaded: "Load suggested types" only fills an editable list with names, categories and whether they come in sizes,
 * and nothing is saved until the officer saves it. Types are deactivated, never deleted.
 */
class PpeTypes extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $category = 'head';

    public bool $hasSizes = false;

    public string $sizes = '';

    public string $replacementMonths = '';

    public bool $hasExpiry = false;

    public string $unit = 'each';

    /** @var list<array{name: string, category: string, has_sizes: bool, sizes: string, replacement_months: string}> */
    public array $suggested = [];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');
    }

    public function create(): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $typeId): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');
        $type = HsPpeType::query()->findOrFail($typeId);

        $this->resetForm();
        $this->editingId = $type->id;
        $this->name = $type->name;
        $this->category = $type->category;
        $this->hasSizes = $type->has_sizes;
        $this->sizes = implode(', ', $type->sizeList());
        $this->replacementMonths = (string) $type->replacement_months;
        $this->hasExpiry = $type->has_expiry;
        $this->unit = $type->unit;
        $this->showForm = true;
    }

    public function save(PpeSetupService $setup): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');
        $this->resetErrorBag();

        $type = $this->editingId ? HsPpeType::query()->findOrFail($this->editingId) : null;

        $setup->saveType($this->actor(), $type, [
            'name' => $this->name,
            'category' => $this->category,
            'has_sizes' => $this->hasSizes,
            'sizes' => $this->sizes,
            'replacement_months' => $this->replacementMonths,
            'has_expiry' => $this->hasExpiry,
            'unit' => $this->unit,
        ]);

        $this->resetForm();
        $this->dispatch('toast', type: 'success', message: 'PPE type saved.');
    }

    public function toggleActive(int $typeId, PpeSetupService $setup): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');
        $type = HsPpeType::query()->findOrFail($typeId);

        $setup->saveType($this->actor(), $type, [
            'name' => $type->name,
            'category' => $type->category,
            'has_sizes' => $type->has_sizes,
            'sizes' => $type->sizeList(),
            'replacement_months' => $type->replacement_months,
            'has_expiry' => $type->has_expiry,
            'unit' => $type->unit,
            'is_active' => ! $type->is_active,
        ]);

        $this->dispatch('toast', type: 'success', message: $type->fresh()->is_active ? 'Type reactivated.' : 'Type deactivated.');
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    // ------------------------------------------------------------------ suggested types

    /** Fill the list (not the database) with common PPE names; sizes and service lives are for EHS to fill in. */
    public function loadSuggested(): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');

        $existing = HsPpeType::query()->pluck('name')->map(fn ($name) => mb_strtolower($name))->all();

        $this->suggested = collect(HsPpeType::SUGGESTED)
            ->reject(fn (array $item) => in_array(mb_strtolower($item[0]), $existing, true))
            ->map(fn (array $item) => ['name' => $item[0], 'category' => $item[1], 'has_sizes' => $item[2], 'sizes' => '', 'replacement_months' => ''])
            ->values()
            ->all();
    }

    public function removeSuggested(int $index): void
    {
        unset($this->suggested[$index]);
        $this->suggested = array_values($this->suggested);
    }

    public function saveSuggested(PpeSetupService $setup): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');
        $this->resetErrorBag();

        $saved = $setup->saveTypes($this->actor(), $this->suggested);

        $this->suggested = [];
        $this->dispatch('toast', type: 'success', message: count($saved).' PPE type'.(count($saved) === 1 ? '' : 's').' saved.');
    }

    public function discardSuggested(): void
    {
        $this->suggested = [];
        $this->resetErrorBag();
    }

    protected function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->name = '';
        $this->category = 'head';
        $this->hasSizes = false;
        $this->sizes = '';
        $this->replacementMonths = '';
        $this->hasExpiry = false;
        $this->unit = 'each';
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.health_safety.ppe-types', [
            'types' => HsPpeType::query()->withCount(['movements', 'issues'])->orderByDesc('is_active')->orderBy('name')->get(),
            'categories' => HsPpeType::CATEGORIES,
            'editingInUse' => $this->editingId ? HsPpeType::query()->find($this->editingId)?->isInUse() : false,
        ]);
    }
}
