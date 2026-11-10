<?php

namespace App\Livewire\Audit;

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
 * Batches as Audit sees them (sent ones only). The Audit manager sees every sent batch of the location with all its vouchers counted
 * (a minimal view); an officer sees the batches that hold a voucher they may open, and the count, total and state are those of THEIR
 * vouchers in it, so a batch never tells an officer about vouchers that are not theirs. Reach is FinanceScope::batches().
 */
class Batches extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;
    use WithPagination;

    public string $search = '';

    public function mount(): void
    {
        $this->enforceFinance(Permission::MODULE_AUDIT);
        abort_unless($this->scope()->auditReach($this->actor()) !== FinanceScope::AUDIT_NONE, 403, 'You do not have permission to do that.');
    }

    public function updated(string $property): void
    {
        if ($property === 'search') {
            $this->resetPage();
        }
    }

    public function render()
    {
        $term = trim($this->search);
        $actor = $this->actor();
        $wholeBatch = $this->scope()->seesAll($actor) || $this->can('audit.assign');

        $rows = $this->batches(FinanceScope::AS_AUDIT)
            ->when($term !== '', function ($query) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $query->where(fn ($inner) => $inner->where('fin_pv_batches.batch_no', 'like', $like)->orWhere('fin_pv_batches.title', 'like', $like));
            })
            ->with('district:id,district_name')
            ->orderByDesc('fin_pv_batches.sent_at')->orderByDesc('fin_pv_batches.id')
            ->paginate(15);

        // The vouchers counted for each batch: all of them for the manager, only the officer's own otherwise (minimal columns either way).
        $members = $rows->count() === 0 ? collect() : ($wholeBatch ? $this->scope()->assignmentQueue($actor) : $this->scope()->pvs($actor, FinanceScope::AS_AUDIT)->select(FinanceScope::QUEUE_COLUMNS))
            ->whereIn('fin_pvs.batch_id', $rows->pluck('id'))->get()->groupBy('batch_id');
        $officerIds = $members->flatten()->pluck('assigned_to_user_id')->filter()->unique()->all();
        $names = $officerIds === [] ? collect() : User::query()->whereIn('id', $officerIds)->pluck('full_name', 'id');

        $summaries = $rows->getCollection()->mapWithKeys(function (FinPvBatch $batch) use ($members, $names) {
            $own = $members->get($batch->id, collect());

            return [$batch->id => [
                'state' => FinPvBatch::computeState((string) $batch->status, $own),
                'count' => $own->count(),
                'total' => Money::format((int) $own->sum('amount')),
                'officers' => $own->pluck('assigned_to_user_id')->filter()->unique()->map(fn ($id) => $names[$id] ?? 'Unknown user')->sort()->values()->all(),
            ]];
        });

        return view('livewire.audit.batches', [
            'rows' => $rows,
            'summaries' => $summaries,
            'wholeBatch' => $wholeBatch,
            'isSuperAdmin' => $this->isSuperAdmin(),
        ]);
    }
}
