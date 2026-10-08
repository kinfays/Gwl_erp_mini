<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsPpeEntitlement;
use App\Models\HsPpeType;
use App\Models\JobTitle;
use App\Models\Permission;
use App\Services\HealthSafety\PpeSetupService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * What each job title is entitled to, as a matrix: job titles down the side, active PPE types across the top, the quantity
 * in each cell. Edit one job title at a time. Only job titles that have staff in the viewer's part of the register, or
 * that already have entitlements, are listed.
 */
class PpeEntitlements extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithPagination;

    public string $search = '';

    #[Locked]
    public ?int $editingTitleId = null;

    /** ppe_type_id => quantity (empty: no entitlement). @var array<int, int|string|null> */
    public array $quantities = [];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function edit(int $titleId): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');
        $title = JobTitle::query()->findOrFail($titleId);

        $current = HsPpeEntitlement::query()->where('job_title_id', $title->id)->pluck('quantity', 'ppe_type_id');

        $this->editingTitleId = $title->id;
        $this->quantities = HsPpeType::query()->active()->pluck('id')->mapWithKeys(fn ($id) => [$id => $current[$id] ?? ''])->all();
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->editingTitleId = null;
        $this->quantities = [];
        $this->resetErrorBag();
    }

    public function save(PpeSetupService $setup): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');
        $this->resetErrorBag();

        $title = JobTitle::query()->findOrFail($this->editingTitleId);
        $set = $setup->saveEntitlements($this->actor(), $title, $this->quantities);

        $this->cancel();
        $this->dispatch('toast', type: 'success', message: $title->job_title_name.': entitled to '.$set.' PPE type'.($set === 1 ? '' : 's').'.');
    }

    public function render()
    {
        $actor = $this->actor();
        $types = HsPpeType::query()->active()->orderBy('name')->get(['id', 'name']);

        $inScope = $this->equipmentScope()->employees($actor)->select('job_title_id');
        $entitled = HsPpeEntitlement::query()->select('job_title_id');

        $titles = JobTitle::query()
            ->where(fn (Builder $query) => $query->whereIn('id', $inScope)->orWhereIn('id', $entitled))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where('job_title_name', 'like', '%'.trim($this->search).'%'))
            ->orderBy('job_title_name')
            ->paginate(20);

        $cells = HsPpeEntitlement::query()
            ->whereIn('job_title_id', $titles->pluck('id'))
            ->get()
            ->groupBy('job_title_id')
            ->map(fn ($rows) => $rows->pluck('quantity', 'ppe_type_id'));

        $staffCounts = $this->equipmentScope()->employees($actor)
            ->whereIn('job_title_id', $titles->pluck('id'))
            ->selectRaw('job_title_id, count(*) as total')
            ->groupBy('job_title_id')
            ->pluck('total', 'job_title_id');

        return view('livewire.health_safety.ppe-entitlements', [
            'types' => $types,
            'titles' => $titles,
            'cells' => $cells,
            'staffCounts' => $staffCounts,
            'editingTitle' => $this->editingTitleId ? JobTitle::query()->find($this->editingTitleId) : null,
        ]);
    }
}
