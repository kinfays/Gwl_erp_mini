<?php

namespace App\Livewire\Audit;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\PreparesPrint;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\FinPaymentVoucher;
use App\Services\Finance\PvCatalogService;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Audit's review queue: the vouchers of the user's location that have reached Audit (never a draft), waiting ones first.
 * Reach is FinanceScope::pvs(..., 'audit'); the screen adds filters only.
 */
class Queue extends Component
{
    use EnforcesModuleAccess;
    use PreparesPrint;
    use ScopesFinanceByActor;
    use WithPagination;

    public string $status = FinPaymentVoucher::WITH_AUDIT;

    public string $search = '';

    public string $from = '';

    public string $to = '';

    public string $categoryId = '';

    protected function printAs(): string
    {
        return FinanceScope::AS_AUDIT;
    }

    public function mount(): void
    {
        $this->enforceFinance(Permission::MODULE_AUDIT);
        abort_unless($this->scope()->auditReach($this->actor()) !== FinanceScope::AUDIT_NONE, 403, 'You do not have permission to do that.');

        // The Audit manager sees only vouchers already worked on, whatever their status: start from all of them.
        if ($this->scope()->auditReach($this->actor()) === FinanceScope::AUDIT_WORKED) {
            $this->status = '';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search', 'from', 'to', 'categoryId'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'from', 'to', 'categoryId']);
        $this->status = $this->scope()->auditReach($this->actor()) === FinanceScope::AUDIT_WORKED ? '' : FinPaymentVoucher::WITH_AUDIT;
        $this->resetPage();
    }

    public function render(PvCatalogService $catalogue)
    {
        $term = trim($this->search);

        $rows = $this->pvs(FinanceScope::AS_AUDIT)
            ->with(['events.actor', 'category', 'type', 'region', 'district', 'batch:id,batch_no'])
            ->when($this->status !== '', fn ($query) => $query->where('fin_pvs.status', $this->status))
            ->when($this->categoryId !== '', fn ($query) => $query->where('fin_pvs.category_id', (int) $this->categoryId))
            ->when($this->from !== '', fn ($query) => $query->whereDate('fin_pvs.pv_date', '>=', $this->from))
            ->when($this->to !== '', fn ($query) => $query->whereDate('fin_pvs.pv_date', '<=', $this->to))
            ->when($term !== '', fn ($query) => $query->where(function ($inner) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $inner->where('fin_pvs.pv_no', 'like', $like)->orWhere('fin_pvs.payee_name', 'like', $like);
            }))
            // The ones that have waited longest come first.
            ->orderBy('fin_pvs.last_submitted_at')
            ->orderBy('fin_pvs.id')
            ->paginate(15);

        $catalogue->labelPvs($rows->items());

        return view('livewire.audit.queue', [
            'rows' => $rows,
            'statuses' => collect(FinPaymentVoucher::STATUSES)->except(FinPaymentVoucher::DRAFT)->all(),
            'categories' => $catalogue->effective($this->workingLocation()),
            'showRoute' => 'audit.pvs.show',
            'batchRoute' => 'audit.batches.show',
            'printable' => true,
            'mine' => $this->can('audit.review'),
            'party' => 'audit',
            'payeeFlags' => app(\App\Services\Finance\PayeeStatusService::class)->warningsFor($rows->items()),
        ] + $this->locationView());
    }
}
