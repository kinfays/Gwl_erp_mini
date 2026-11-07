<?php

namespace App\Services\Finance;

use App\Models\FinPaymentVoucher;
use App\Models\FinSupplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the Suppliers screens READ. The list itself is company-wide (any Finance or Audit user may read every supplier), but the
 * figures beside a supplier (how many vouchers, how much, when last used) are worked out ONLY through FinanceScope::pvs(), so a
 * location sees what was paid to a supplier by itself and never by another location; super_admin reads every location. Audit's
 * figures never include a draft (FinanceScope's audit view). Vouchers that were never sent to Audit (drafts) and voided ones are
 * not counted: "used" means a voucher that went ahead. Writing is SupplierService's.
 */
class SupplierDirectory
{
    public function __construct(protected FinanceScope $scope) {}

    /**
     * @param  string  $status  active | inactive | all
     * @return Builder<FinSupplier>
     */
    public function suppliers(string $search = '', string $status = 'active'): Builder
    {
        $term = trim($search);

        return FinSupplier::query()
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($term !== '', function (Builder $query) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $query->where(function (Builder $inner) use ($like) {
                    $inner->where('name', 'like', $like)->orWhere('tin', 'like', $like)->orWhere('code', 'like', $like)->orWhere('phone', 'like', $like);
                });
            })
            ->orderBy('name');
    }

    /**
     * @param  list<int>  $supplierIds
     * @return array<int, array{count: int, total: int, last_date: string|null}> by supplier id (suppliers never used are absent)
     */
    public function figures(User $user, string $as, array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }

        return $this->used($user, $as)
            ->whereIn('fin_pvs.supplier_id', $supplierIds)
            ->selectRaw('fin_pvs.supplier_id as supplier_id, COUNT(*) as vouchers, COALESCE(SUM(fin_pvs.amount), 0) as total, MAX(fin_pvs.pv_date) as last_date')
            ->groupBy('fin_pvs.supplier_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->supplier_id => [
                'count' => (int) $row->vouchers,
                'total' => (int) $row->total,
                'last_date' => $row->last_date ? substr((string) $row->last_date, 0, 10) : null,
            ]])
            ->all();
    }

    /** The newest vouchers paid to one supplier that the user reaches. */
    public function recentVouchers(User $user, string $as, int $supplierId, int $limit = 10): Collection
    {
        return $this->used($user, $as)
            ->where('fin_pvs.supplier_id', $supplierId)
            ->orderByRaw('DATE(fin_pvs.pv_date) desc')
            ->orderByDesc('fin_pvs.id')
            ->limit($limit)
            ->get(['fin_pvs.id', 'fin_pvs.pv_no', 'fin_pvs.pv_date', 'fin_pvs.amount', 'fin_pvs.status']);
    }

    /** Vouchers paid to a supplier that went ahead (not a draft, not voided), inside the user's reach. */
    protected function used(User $user, string $as): Builder
    {
        return $this->scope->pvs($user, $as)
            ->where('fin_pvs.payee_kind', FinPaymentVoucher::PAYEE_SUPPLIER)
            ->whereNotIn('fin_pvs.status', [FinPaymentVoucher::DRAFT, FinPaymentVoucher::VOIDED]);
    }
}
