<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\FinPvBatch;
use App\Models\Permission;
use App\Models\User;
use App\Services\Finance\FinanceScope;
use App\Services\GWater\Money;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Finance > Batches: every batch of the user's location (drafts and cancelled ones too), with the count, the total, the computed
 * state (FinPvBatch::computeState, never re-derived here), who Audit gave the vouchers to and when it was sent. Read-only for the
 * Finance manager; reach is FinanceScope::batches() (ScopesFinanceByActor only wraps it).
 */
class Batches extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;
    use WithPagination;

    /** The batch's own status (draft | sent | cancelled), which is a column. Its computed state is shown but never filtered in SQL. */
    public string $status = '';

    public string $search = '';

    public function mount(): void
    {
        $this->enforceFinance(Permission::MODULE_FINANCE);
        $this->guard('finance.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['status', 'search']);
        $this->resetPage();
    }

    public function render()
    {
        $term = trim($this->search);

        $rows = $this->batches(FinanceScope::AS_FINANCE)
            ->with(['vouchers:id,batch_id,status,assigned_to_user_id,amount', 'district:id,district_name'])
            ->when($this->status !== '', fn ($query) => $query->where('fin_pv_batches.status', $this->status))
            ->when($term !== '', fn ($query) => $query->where(function ($inner) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $inner->where('fin_pv_batches.batch_no', 'like', $like)->orWhere('fin_pv_batches.title', 'like', $like);
            }))
            ->orderByDesc('fin_pv_batches.id')
            ->paginate(15);

        $officerIds = $rows->getCollection()->flatMap(fn (FinPvBatch $batch) => $batch->vouchers->pluck('assigned_to_user_id'))->filter()->unique()->all();
        $officers = $officerIds === [] ? collect() : User::query()->whereIn('id', $officerIds)->pluck('full_name', 'id');

        $summaries = $rows->getCollection()->mapWithKeys(fn (FinPvBatch $batch) => [$batch->id => [
            'state' => $batch->state(),
            'count' => $batch->vouchers->count(),
            'total' => Money::format((int) $batch->vouchers->sum('amount')),
            'officers' => $batch->vouchers->pluck('assigned_to_user_id')->filter()->unique()->map(fn ($id) => $officers[$id] ?? 'Unknown user')->sort()->values()->all(),
        ]]);

        return view('livewire.finance.batches', [
            'rows' => $rows,
            'summaries' => $summaries,
            'statuses' => FinPvBatch::STATUSES,
            'isSuperAdmin' => $this->isSuperAdmin(),
        ]);
    }
}
