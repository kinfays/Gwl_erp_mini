<?php

namespace App\Livewire\Audit;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\FinPaymentVoucher;
use App\Models\FinPvBatch;
use App\Models\Permission;
use App\Models\User;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvCatalogService;
use App\Services\GWater\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One batch as Audit sees it.
 *  - An officer sees the vouchers of the batch they may open (assigned to them, or worked on by them), each with its marks, and may
 *    "Acknowledge all remaining": one tick and one event per voucher; a query is never bulk.
 *  - The Audit manager sees EVERY voucher of the batch but only the minimal columns of one nobody has worked on (no payee, no
 *    details, no Open link); one an officer has acted on opens as usual. The manager assigns the whole batch, or ticked vouchers
 *    (so one batch can be split across officers), and reassigns the same way.
 * Reach is FinanceScope::batches() / pvs() / assignmentQueue(); every rule is BatchWorkflowService's.
 */
class BatchShow extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;

    #[Locked]
    public int $batchId;

    /** @var array<int, string> the ticked vouchers (the manager's selection) */
    public array $selected = [];

    public string $officerId = '';

    public string $note = '';

    /** @var array<string, mixed>|null what the last "Acknowledge all remaining" did */
    public ?array $bulk = null;

    public function mount(int $batch): void
    {
        $this->enforceFinance(Permission::MODULE_AUDIT);
        abort_unless($this->scope()->auditReach($this->actor()) !== FinanceScope::AUDIT_NONE, 403, 'You do not have permission to do that.');
        $this->batchId = $this->batchOrFail($batch, FinanceScope::AS_AUDIT)->id;
    }

    public function updated(string $property): void
    {
        if ($property === 'selected') {
            $this->officerId = '';   // the eligible officers depend on what is ticked
        }
    }

    protected function manages(): bool
    {
        return $this->can('audit.assign');
    }

    /** The minimal rows of the batch this user may list: every voucher for the manager, otherwise only the ones they may open. */
    protected function memberQuery()
    {
        $query = $this->manages() ? $this->scope()->assignmentQueue($this->actor()) : $this->pvs(FinanceScope::AS_AUDIT)->select(FinanceScope::QUEUE_COLUMNS);

        return $query->where('fin_pvs.batch_id', $this->batchId)->orderBy('fin_pvs.id');
    }

    /** The ticked vouchers that are really in this batch and can be assigned (With Audit). */
    protected function tickedIds(): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $this->selected))));

        return $ids === [] ? [] : $this->memberQuery()->where('fin_pvs.status', FinPaymentVoucher::WITH_AUDIT)->whereIn('fin_pvs.id', $ids)->pluck('fin_pvs.id')->map(fn ($id) => (int) $id)->all();
    }

    public function assignTicked(): void
    {
        $this->guard('audit.assign');
        $this->resetErrorBag();
        $ids = $this->tickedIds();

        if ($ids === []) {
            $this->addError('assign.vouchers', 'Tick at least one voucher that is waiting for Audit.');

            return;
        }

        if ((int) $this->officerId < 1) {
            $this->addError('assign.officer', 'Choose an Audit officer.');

            return;
        }

        $result = $this->run(fn () => $this->batchService()->assignVouchers($this->actor(), $ids, (int) $this->officerId, $this->note), 'assign');
        $this->afterAssign($result['assigned'] ?? null, $result['unchanged'] ?? 0);
    }

    public function assignWhole(): void
    {
        $this->guard('audit.assign');
        $this->resetErrorBag();

        if ((int) $this->officerId < 1) {
            $this->addError('assign.officer', 'Choose an Audit officer.');

            return;
        }

        $result = $this->run(fn () => $this->batchService()->assignBatch($this->actor(), $this->batchId, (int) $this->officerId, $this->note), 'assign');
        $this->afterAssign($result['assigned'] ?? null, $result['unchanged'] ?? 0);
    }

    protected function afterAssign(?int $assigned, int $unchanged): void
    {
        if ($assigned === null) {
            return;
        }

        $this->dispatch('toast', type: 'success', message: $assigned.' '.($assigned === 1 ? 'voucher' : 'vouchers').' assigned'.($unchanged > 0 ? ', '.$unchanged.' already with that officer.' : '.'));
        $this->selected = [];
        $this->officerId = '';
        $this->note = '';
    }

    public function acknowledgeRemaining(): void
    {
        $this->guard('audit.review');
        $this->resetErrorBag();
        $this->bulk = $this->run(fn () => $this->batchService()->acknowledgeRemaining($this->actor(), $this->batchId), 'action');

        if ($this->bulk !== null) {
            $this->dispatch('toast', type: 'success', message: $this->bulk['acknowledged'].' acknowledged.');
        }
    }

    public function render(PvCatalogService $catalogue)
    {
        $batch = $this->batchOrFail($this->batchId, FinanceScope::AS_AUDIT);
        $batch->load(['sender:id,full_name', 'district:id,district_name', 'events.actor:id,full_name', 'events.toUser:id,full_name', 'events.pv:id,pv_no']);

        $minimal = $this->memberQuery()->get();
        $openable = $this->pvs(FinanceScope::AS_AUDIT)->where('fin_pvs.batch_id', $this->batchId)
            ->with(['events.actor', 'category', 'type'])->get()->keyBy('id');
        $catalogue->labelPvs($openable->all());
        $catalogue->labelPvs($minimal->all());

        $userIds = $minimal->pluck('assigned_to_user_id')->merge($minimal->pluck('assigned_by_user_id'))->filter()->unique()->all();
        $names = $userIds === [] ? collect() : User::query()->whereIn('id', $userIds)->pluck('full_name', 'id');

        $state = FinPvBatch::computeState((string) $batch->status, $minimal);
        $waiting = $minimal->where('status', FinPaymentVoucher::WITH_AUDIT);

        // The officers the manager can pick for what is ticked (or, with nothing ticked, for every voucher still waiting).
        $forIds = $this->tickedIds() ?: $waiting->pluck('id')->map(fn ($id) => (int) $id)->all();
        $choices = collect();
        $choiceError = null;

        if ($this->manages() && $forIds !== []) {
            try {
                $choices = $this->batchService()->officerChoices($this->actor(), $forIds);
            } catch (ValidationException $exception) {
                $choiceError = collect($exception->errors())->flatten()->first();
            }
        }

        $mineWaiting = $this->can('audit.review')
            ? $openable->filter(fn (FinPaymentVoucher $pv) => $pv->status === FinPaymentVoucher::WITH_AUDIT && (int) $pv->assigned_to_user_id === (int) $this->actor()->id)->count()
            : 0;

        return view('livewire.audit.batch-show', [
            'batch' => $batch,
            'members' => $minimal,
            'openable' => $openable,
            'names' => $names,
            'state' => $state,
            'total' => Money::format((int) $minimal->sum('amount')),
            'manages' => $this->manages(),
            'officerOptions' => $choices->all(),
            'choiceError' => $choiceError,
            'pickedKey' => md5(json_encode($forIds)),
            'tickedCount' => count($this->tickedIds()),
            'waitingCount' => $waiting->count(),
            'mineWaiting' => $mineWaiting,
            'wholeBatch' => $this->manages() || $this->scope()->seesAll($this->actor()),
        ]);
    }
}
