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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The Audit manager's assignment queue: every voucher and batch the location has sent to Audit, with MINIMAL columns only (a
 * reference, category/type, count, total, when it was sent, how long it has waited, who holds it): the manager cannot see a payee,
 * the details or a file of a voucher nobody has worked on. Pick vouchers and batches, pick an officer (the combobox offers only
 * eligible officers, each with their open workload), assign; the same screen reassigns. All reach is FinanceScope::assignmentQueue()
 * and assignmentBatches(); every rule is BatchWorkflowService / PaymentVoucherWorkflowService.
 *
 * The default list is a WORKLIST ("needs an officer now": a batch with a voucher that is With Audit and has none, a voucher that is
 * With Audit and has none). It is not the batch state, which stays FinPvBatch::computeState().
 */
class Assignments extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;
    use WithPagination;

    private const PER_PAGE = 10;

    /** awaiting | all */
    public string $show = 'awaiting';

    public string $search = '';

    /** @var array<int, string> */
    public array $selectedBatches = [];

    /** @var array<int, string> */
    public array $selectedPvs = [];

    public string $officerId = '';

    public string $note = '';

    public function mount(): void
    {
        $this->enforceFinance(Permission::MODULE_AUDIT);
        $this->guard('audit.assign');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['show', 'search', 'locationId'], true)) {
            $this->resetPage('batchPage');
            $this->resetPage('pvPage');
            $this->selectedBatches = [];
            $this->selectedPvs = [];
            $this->officerId = '';
        }

        if (in_array($property, ['selectedBatches', 'selectedPvs'], true)) {
            $this->officerId = '';   // the eligible officers depend on what is picked
        }
    }

    // ------------------------------------------------------------------ the queues

    /** Limits a query to the location being worked in (the user's own, or the one super_admin chose; nothing until chosen). */
    protected function inLocation(Builder $query): Builder
    {
        $location = $this->workingLocation();

        return $location ? $this->scope()->atLocation($query, $location) : $query->whereRaw('1 = 0');
    }

    protected function looseQuery(): Builder
    {
        $term = trim($this->search);

        return $this->inLocation($this->scope()->assignmentQueue($this->actor()))
            ->whereNull('fin_pvs.batch_id')
            ->when($this->show === 'awaiting', fn ($query) => $query->where('fin_pvs.status', FinPaymentVoucher::WITH_AUDIT)->whereNull('fin_pvs.assigned_to_user_id'))
            ->when($term !== '', fn ($query) => $query->where('fin_pvs.pv_no', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%'))
            ->orderBy('fin_pvs.last_submitted_at')->orderBy('fin_pvs.id');
    }

    protected function batchQuery(): Builder
    {
        $term = trim($this->search);

        return $this->inLocation($this->scope()->assignmentBatches($this->actor()))
            ->when($this->show === 'awaiting', fn ($query) => $query->whereExists(fn ($members) => $members->selectRaw('1')->from('fin_pvs')
                ->whereColumn('fin_pvs.batch_id', 'fin_pv_batches.id')
                ->where('fin_pvs.status', FinPaymentVoucher::WITH_AUDIT)->whereNull('fin_pvs.assigned_to_user_id')))
            ->when($term !== '', function ($query) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $query->where(fn ($inner) => $inner->where('fin_pv_batches.batch_no', 'like', $like)->orWhere('fin_pv_batches.title', 'like', $like));
            })
            ->orderBy('fin_pv_batches.sent_at')->orderBy('fin_pv_batches.id');
    }

    /**
     * What is really picked, re-read inside the manager's queue (a tampered id drops out): the loose vouchers that can be assigned and
     * the batches, plus the ids of every voucher an assignment would touch (for the officer picker).
     *
     * @return array{batches: list<int>, pvs: list<int>, ids: list<int>}
     */
    protected function picked(): array
    {
        $batchIds = array_values(array_unique(array_filter(array_map('intval', $this->selectedBatches))));
        $pvIds = array_values(array_unique(array_filter(array_map('intval', $this->selectedPvs))));

        $batches = $batchIds === [] ? [] : $this->inLocation($this->scope()->assignmentBatches($this->actor()))->whereIn('fin_pv_batches.id', $batchIds)->pluck('fin_pv_batches.id')->all();
        $loose = $pvIds === [] ? [] : $this->inLocation($this->scope()->assignmentQueue($this->actor()))->whereNull('fin_pvs.batch_id')
            ->where('fin_pvs.status', FinPaymentVoucher::WITH_AUDIT)->whereIn('fin_pvs.id', $pvIds)->pluck('fin_pvs.id')->all();
        $members = $batches === [] ? [] : $this->scope()->assignmentQueue($this->actor())->whereIn('fin_pvs.batch_id', $batches)
            ->where('fin_pvs.status', FinPaymentVoucher::WITH_AUDIT)->pluck('fin_pvs.id')->all();

        return ['batches' => array_map('intval', $batches), 'pvs' => array_map('intval', $loose), 'ids' => array_values(array_unique([...array_map('intval', $loose), ...array_map('intval', $members)]))];
    }

    public function clearSelection(): void
    {
        $this->selectedBatches = [];
        $this->selectedPvs = [];
        $this->officerId = '';
        $this->note = '';
        $this->resetErrorBag();
    }

    public function assign(): void
    {
        $this->guard('audit.assign');
        $this->resetErrorBag();
        $picked = $this->picked();
        $officer = (int) $this->officerId;

        if ($picked['batches'] === [] && $picked['pvs'] === []) {
            $this->addError('assign.vouchers', 'Pick at least one voucher or batch that is waiting for Audit.');

            return;
        }

        if ($officer < 1) {
            $this->addError('assign.officer', 'Choose an Audit officer.');

            return;
        }

        $result = $this->run(function () use ($picked, $officer) {
            return DB::transaction(function () use ($picked, $officer) {
                $assigned = 0;
                $unchanged = 0;

                foreach ($picked['batches'] as $batchId) {
                    $one = $this->batchService()->assignBatch($this->actor(), $batchId, $officer, $this->note);
                    $assigned += $one['assigned'];
                    $unchanged += $one['unchanged'];
                }

                if ($picked['pvs'] !== []) {
                    $one = $this->batchService()->assignVouchers($this->actor(), $picked['pvs'], $officer, $this->note);
                    $assigned += $one['assigned'];
                    $unchanged += $one['unchanged'];
                }

                return ['assigned' => $assigned, 'unchanged' => $unchanged];
            });
        }, 'assign');

        if ($result !== null) {
            $this->dispatch('toast', type: 'success', message: $result['assigned'].' '.($result['assigned'] === 1 ? 'voucher' : 'vouchers').' assigned'.($result['unchanged'] > 0 ? ', '.$result['unchanged'].' already with that officer.' : '.'));
            $this->clearSelection();
        }
    }

    public function render(PvCatalogService $catalogue)
    {
        $batches = $this->batchQuery()->paginate(self::PER_PAGE, pageName: 'batchPage');
        $loose = $this->looseQuery()->paginate(self::PER_PAGE, pageName: 'pvPage');
        $catalogue->labelPvs($loose->items());

        // The minimal member columns of the batches on this page: enough for count, total and the computed state.
        $members = $batches->count() === 0 ? collect() : $this->scope()->assignmentQueue($this->actor())->whereIn('fin_pvs.batch_id', $batches->pluck('id'))->get()->groupBy('batch_id');
        $userIds = $members->flatten()->pluck('assigned_to_user_id')->merge($loose->getCollection()->pluck('assigned_to_user_id'))->merge($loose->getCollection()->pluck('assigned_by_user_id'))->filter()->unique()->all();
        $names = $userIds === [] ? collect() : User::query()->whereIn('id', $userIds)->pluck('full_name', 'id');

        $summaries = $batches->getCollection()->mapWithKeys(function (FinPvBatch $batch) use ($members, $names) {
            $own = $members->get($batch->id, collect());

            return [$batch->id => [
                'state' => FinPvBatch::computeState((string) $batch->status, $own),
                'count' => $own->count(),
                'total' => Money::format((int) $own->sum('amount')),
                'officers' => $own->pluck('assigned_to_user_id')->filter()->unique()->map(fn ($id) => $names[$id] ?? 'Unknown user')->sort()->values()->all(),
                'waiting' => $batch->sent_at ? (int) $batch->sent_at->diffInDays(now()) : 0,
            ]];
        });

        $picked = $this->picked();
        $choices = collect();
        $choiceError = null;

        if ($picked['ids'] !== []) {
            try {
                $choices = $this->batchService()->officerChoices($this->actor(), $picked['ids']);
            } catch (ValidationException $exception) {
                $choiceError = collect($exception->errors())->flatten()->first();
            }
        }

        return view('livewire.audit.assignments', [
            'batches' => $batches,
            'loose' => $loose,
            'summaries' => $summaries,
            'names' => $names,
            'officerOptions' => $choices->all(),
            'choiceError' => $choiceError,
            'pickedCount' => count($picked['ids']),
            'pickedBatches' => count($picked['batches']),
            'pickedKey' => md5(json_encode($picked['ids'])),
        ] + $this->locationView());
    }
}
