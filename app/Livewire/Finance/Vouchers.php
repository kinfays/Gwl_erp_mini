<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\FinPaymentVoucher;
use App\Services\Finance\PvCatalogService;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Finance's list of payment vouchers (drafts included) in the user's location; super_admin reads every location. Each row
 * carries the Audit and Finance marks. All reach is FinanceScope's (ScopesFinanceByActor only wraps it).
 */
class Vouchers extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;
    use WithPagination;

    public string $status = '';

    public string $search = '';

    public string $from = '';

    public string $to = '';

    public string $categoryId = '';

    public function mount(): void
    {
        $this->enforceFinance(Permission::MODULE_FINANCE);
        $this->guard('finance.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search', 'from', 'to', 'categoryId'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['status', 'search', 'from', 'to', 'categoryId']);
        $this->resetPage();
    }

    public function render(PvCatalogService $catalogue)
    {
        $term = trim($this->search);

        $rows = $this->pvs(FinanceScope::AS_FINANCE)
            ->with(['events.actor', 'category', 'type', 'region', 'district'])
            ->when($this->status !== '', fn ($query) => $query->where('fin_pvs.status', $this->status))
            ->when($this->categoryId !== '', fn ($query) => $query->where('fin_pvs.category_id', (int) $this->categoryId))
            ->when($this->from !== '', fn ($query) => $query->whereDate('fin_pvs.pv_date', '>=', $this->from))
            ->when($this->to !== '', fn ($query) => $query->whereDate('fin_pvs.pv_date', '<=', $this->to))
            ->when($term !== '', fn ($query) => $query->where(function ($inner) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $inner->where('fin_pvs.pv_no', 'like', $like)->orWhere('fin_pvs.payee_name', 'like', $like);
            }))
            ->orderByRaw('DATE(fin_pvs.pv_date) desc')
            ->orderByDesc('fin_pvs.id')
            ->paginate(15);

        $catalogue->labelPvs($rows->items());

        return view('livewire.finance.vouchers', [
            'rows' => $rows,
            'statuses' => FinPaymentVoucher::STATUSES,
            'categories' => $catalogue->effective($this->workingLocation()),
            'canCreate' => $this->can('finance.create_pv'),
            'showRoute' => 'finance.pvs.show',
            'party' => 'finance',
        ] + $this->locationView());
    }
}
