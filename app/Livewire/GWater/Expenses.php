<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Models\GwExpense;
use App\Models\GwPurchase;
use App\Services\GWater\GwaterWorkflowService;
use App\Services\GWater\Money;
use App\Services\Transport\TransportScope;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Expenses: fuel, loading, offloading and other costs, optionally tied to a trip. Only FUEL can be paid back to the person who
 * paid it out of pocket (the Reimbursements screen); loading and offloading are paid in cash. Fuel is counted once per trip.
 */
class Expenses extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;
    use WithPagination;

    public array $form = [];

    public string $from = '';

    public string $to = '';

    public string $category = '';

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.view');

        $this->resetForm();
        $this->from = today()->subDays(90)->toDateString();
        $this->to = today()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'category', 'locationId'], true)) {
            $this->resetPage();
        }

        if ($property === 'locationId') {
            foreach (['purchase_id', 'vehicle_id', 'paid_by_user_id'] as $field) {
                $this->form[$field] = '';
            }
        }

        // Labour is paid in cash: drop a payer picked for fuel when the category changes.
        if ($property === 'form.category' && $this->form['category'] !== GwExpense::FUEL) {
            $this->form['paid_by_user_id'] = '';
        }
    }

    public function save(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.record');

        $amount = Money::toPesewas($this->form['amount'] ?? null);

        if ($amount === null) {
            throw ValidationException::withMessages(['form.amount' => 'Enter the amount in cedis, with at most two decimals.']);
        }

        $this->run(fn () => $workflow->recordExpense($this->actor(), [...$this->form, 'amount' => $amount, 'location_district_id' => $this->recordingDistrict()]));

        $this->form['amount'] = '';
        $this->form['receipt_ref'] = '';
        $this->form['note'] = '';
        $this->form['paid_by_user_id'] = '';
        $this->resetErrorBag();
        $this->dispatch('toast', type: 'success', message: 'Expense recorded.');
    }

    public function openVoid(int $expenseId): void
    {
        $this->guard('gwater.void');
        $this->books(GwExpense::query())->findOrFail($expenseId);

        $this->voidingId = $expenseId;
        $this->voidReason = '';
        $this->resetErrorBag();
    }

    public function cancelVoid(): void
    {
        $this->voidingId = null;
        $this->voidReason = '';
    }

    public function confirmVoid(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.void');
        abort_unless($this->voidingId, 422);

        $expense = $this->books(GwExpense::query())->findOrFail($this->voidingId);
        $this->run(fn () => $workflow->voidExpense($expense, $this->actor(), $this->voidReason), 'void');

        $this->cancelVoid();
        $this->dispatch('toast', type: 'success', message: 'Expense voided.');
    }

    protected function resetForm(): void
    {
        $this->form = [
            'expense_date' => today()->toDateString(),
            'category' => GwExpense::FUEL,
            'amount' => '',
            'purchase_id' => '',
            'vehicle_id' => '',
            'payee' => '',
            'receipt_ref' => '',
            'paid_by_user_id' => '',
            'note' => '',
        ];
    }

    public function render(TransportScope $transport)
    {
        $location = $this->workingLocation();
        $user = $this->actor();

        $base = fn () => $this->books(GwExpense::query())
            ->when($this->from !== '', fn ($query) => $query->whereDate('expense_date', '>=', $this->from))
            ->when($this->to !== '', fn ($query) => $query->whereDate('expense_date', '<=', $this->to))
            ->when($this->category !== '', fn ($query) => $query->where('category', $this->category));

        $expenses = $location
            ? $base()->with(['purchase', 'paidBy', 'creator', 'vehicle'])->orderByRaw('DATE(expense_date) desc')->orderByDesc('id')->paginate(15)
            : GwExpense::query()->whereRaw('1 = 0')->paginate(15);

        $totals = $location ? $base()->posted()->get()->groupBy('category')->map->sum('amount') : collect();

        return view('livewire.gwater.expenses', [
            'expenses' => $expenses,
            'categories' => GwExpense::CATEGORIES,
            'totals' => $totals,
            'trips' => $location ? $this->books(GwPurchase::query()->posted())->orderByRaw('DATE(purchase_date) desc')->orderByDesc('id')->limit(60)->get() : collect(),
            // Only vehicles the user reaches (TransportScope), and only those based at this location.
            'vehicles' => $location ? $this->visibility()->atLocation($transport->vehicles($user), $location)->where('vehicles.status', '!=', 'retired')->orderBy('vehicles.number_plate')->limit(300)->get() : collect(),
            'people' => $location ? $this->visibility()->peopleAt($location)->orderBy('full_name')->limit(500)->get() : collect(),
            'canRecord' => $this->visibility()->can($user, 'gwater.record'),
            'canVoid' => $this->visibility()->can($user, 'gwater.void'),
        ] + $this->locationView());
    }
}
