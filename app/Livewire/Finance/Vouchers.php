<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\PreparesPrint;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\FinPaymentVoucher;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvCatalogService;
use App\Services\GWater\Money;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Finance's list of payment vouchers (drafts included) in the user's location; super_admin reads every location. Each row
 * carries the Audit and Finance marks. All reach is FinanceScope's (ScopesFinanceByActor only wraps it).
 *
 * Someone who may prepare vouchers can tick Draft vouchers that are in no batch and make a batch from them (BatchWorkflowService
 * decides, again, on every id: the ticks are only a request). A row that cannot be ticked says why.
 */
class Vouchers extends Component
{
    use EnforcesModuleAccess;
    use PreparesPrint;
    use ScopesFinanceByActor;
    use WithPagination;

    private const PER_PAGE = 15;

    public string $status = '';

    public string $search = '';

    public string $from = '';

    public string $to = '';

    public string $categoryId = '';

    /** @var array<int, string> the ticked voucher ids (a request, re-checked by the service) */
    public array $selected = [];

    public bool $showBatch = false;

    public string $batchTitle = '';

    public string $batchRemarks = '';

    protected function printAs(): string
    {
        return FinanceScope::AS_FINANCE;
    }

    public function mount(): void
    {
        $this->enforceFinance(Permission::MODULE_FINANCE);
        $this->guard('finance.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search', 'from', 'to', 'categoryId'], true)) {
            $this->resetPage();
            $this->selected = [];
            $this->printIds = [];
            $this->printUrl = null;
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['status', 'search', 'from', 'to', 'categoryId', 'selected']);
        $this->resetPage();
    }

    protected function query(): Builder
    {
        $term = trim($this->search);

        return $this->pvs(FinanceScope::AS_FINANCE)
            ->when($this->status !== '', fn ($query) => $query->where('fin_pvs.status', $this->status))
            ->when($this->categoryId !== '', fn ($query) => $query->where('fin_pvs.category_id', (int) $this->categoryId))
            ->when($this->from !== '', fn ($query) => $query->whereDate('fin_pvs.pv_date', '>=', $this->from))
            ->when($this->to !== '', fn ($query) => $query->whereDate('fin_pvs.pv_date', '<=', $this->to))
            ->when($term !== '', fn ($query) => $query->where(function ($inner) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $inner->where('fin_pvs.pv_no', 'like', $like)->orWhere('fin_pvs.payee_name', 'like', $like);
            }))
            ->orderByRaw('DATE(fin_pvs.pv_date) desc')
            ->orderByDesc('fin_pvs.id');
    }

    /** Ticks every voucher of this page that can go into a batch (and unticks them all when they were all ticked). */
    public function selectPage(): void
    {
        $this->guard('finance.create_pv');

        $eligible = $this->query()->with('batch:id,batch_no')->forPage($this->getPage(), self::PER_PAGE)->get()
            ->filter(fn (FinPaymentVoucher $pv) => $pv->batchBlocker() === null)->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->selected = $eligible !== [] && array_diff($eligible, $this->selected) === [] ? array_values(array_diff($this->selected, $eligible)) : array_values(array_unique([...$this->selected, ...$eligible]));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->showBatch = false;
    }

    public function openBatch(): void
    {
        $this->guard('finance.create_pv');
        $this->resetErrorBag();
        $this->batchTitle = '';
        $this->batchRemarks = '';
        $this->showBatch = count($this->eligibleSelected()) > 0;
    }

    public function createBatch(): void
    {
        $this->guard('finance.create_pv');
        $chosen = $this->eligibleSelected();

        // super_admin has no location of their own: the batch goes into the books of the vouchers chosen (the service refuses a mix).
        $district = $this->isSuperAdmin() && $chosen !== [] ? (int) $chosen[0]->district_id : null;

        $batch = $this->run(fn () => $this->batchService()->create($this->actor(), array_map(fn ($pv) => $pv->id, $chosen), $this->batchTitle, $this->batchRemarks, $district), 'batch');

        if ($batch) {
            $this->redirectRoute('finance.batches.show', $batch->id);
        }
    }

    /**
     * The ticked vouchers that really can go into a batch, re-read inside the user's reach (a tampered id, another location's voucher
     * or one that has since been sent simply drops out).
     *
     * @return list<FinPaymentVoucher>
     */
    protected function eligibleSelected(): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $this->selected))));

        if ($ids === []) {
            return [];
        }

        return $this->pvs(FinanceScope::AS_FINANCE)->with('batch:id,batch_no')->whereIn('fin_pvs.id', $ids)->orderBy('fin_pvs.id')->get()
            ->filter(fn (FinPaymentVoucher $pv) => $pv->batchBlocker() === null)->values()->all();
    }

    public function render(PvCatalogService $catalogue)
    {
        $rows = $this->query()
            ->with(['events.actor', 'category', 'type', 'region', 'district', 'batch:id,batch_no'])
            ->paginate(self::PER_PAGE);

        $catalogue->labelPvs($rows->items());
        $canCreate = $this->can('finance.create_pv');
        $chosen = $this->showBatch ? $this->eligibleSelected() : [];

        return view('livewire.finance.vouchers', [
            'rows' => $rows,
            'statuses' => FinPaymentVoucher::STATUSES,
            'categories' => $catalogue->effective($this->workingLocation()),
            'canCreate' => $canCreate,
            'selectable' => $canCreate,
            'printable' => true,
            'selectedCount' => $canCreate ? count($this->eligibleSelected()) : 0,
            'chosenCount' => count($chosen),
            'chosenTotal' => Money::format(array_sum(array_map(fn ($pv) => $pv->amount, $chosen))),
            'showRoute' => 'finance.pvs.show',
            'batchRoute' => 'finance.batches.show',
            'party' => 'finance',
        ] + $this->locationView());
    }
}
