<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\FinPaymentVoucher;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvCatalogService;
use App\Services\GWater\Money;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One batch as Finance sees it: its vouchers with their own Audit and Finance marks, the progress, and (while it is a draft) adding
 * and removing vouchers, editing the title and remarks, sending it and cancelling it; once it is sent, Finance's "Acknowledge all".
 * The component keeps only the batch id and the version it opened; the batch is re-resolved inside the user's reach on every call
 * and every rule is BatchWorkflowService's.
 */
class BatchShow extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;

    #[Locked]
    public int $batchId;

    public int $version = 0;

    public bool $showSend = false;

    public bool $showCancel = false;

    public bool $showEdit = false;

    public bool $showAdd = false;

    public string $cancelReason = '';

    public string $title = '';

    public string $remarks = '';

    /** @var array<int, string> */
    public array $removeIds = [];

    /** @var array<int, string> */
    public array $addIds = [];

    /** @var array<string, mixed>|null what the last "Acknowledge all" did */
    public ?array $bulk = null;

    public function mount(int $batch): void
    {
        $this->enforceFinance(Permission::MODULE_FINANCE);
        $this->guard('finance.view');

        $found = $this->batchOrFail($batch, FinanceScope::AS_FINANCE);
        $this->batchId = $found->id;
        $this->sync($found);
    }

    protected function sync($batch): void
    {
        $this->version = $batch->lock_version;
        $this->title = (string) $batch->title;
        $this->remarks = (string) $batch->remarks;
    }

    protected function refresh(): void
    {
        if ($this->getErrorBag()->isEmpty()) {
            $this->sync($this->batchOrFail($this->batchId, FinanceScope::AS_FINANCE));
        }
    }

    // ------------------------------------------------------------------ draft: membership and details

    public function toggleAdd(): void
    {
        $this->guard('finance.create_pv');
        $this->showAdd = ! $this->showAdd;
        $this->addIds = [];
        $this->resetErrorBag();
    }

    public function addSelected(): void
    {
        $this->guard('finance.create_pv');
        $this->run(fn () => $this->batchService()->addVouchers($this->actor(), $this->batchId, $this->addIds, $this->version), 'action');

        if ($this->getErrorBag()->isEmpty()) {
            $this->addIds = [];
            $this->showAdd = false;
            $this->dispatch('toast', type: 'success', message: 'Vouchers added.');
        }

        $this->refresh();
    }

    public function removeSelected(): void
    {
        $this->guard('finance.create_pv');
        $this->run(fn () => $this->batchService()->removeVouchers($this->actor(), $this->batchId, $this->removeIds, $this->version), 'action');

        if ($this->getErrorBag()->isEmpty()) {
            $this->removeIds = [];
            $this->dispatch('toast', type: 'success', message: 'Taken out of the batch.');
        }

        $this->refresh();
    }

    public function saveDetails(): void
    {
        $this->guard('finance.create_pv');
        $this->run(fn () => $this->batchService()->updateDetails($this->actor(), $this->batchId, $this->title, $this->remarks, $this->version), 'action');

        if ($this->getErrorBag()->isEmpty()) {
            $this->showEdit = false;
            $this->dispatch('toast', type: 'success', message: 'Saved.');
        }

        $this->refresh();
    }

    // ------------------------------------------------------------------ send, cancel

    public function openSend(): void
    {
        $this->guard('finance.create_pv');
        $this->resetErrorBag();
        $this->showSend = true;
    }

    public function confirmSend(): void
    {
        $this->guard('finance.create_pv');
        $this->run(fn () => $this->batchService()->send($this->actor(), $this->batchId, $this->version), 'action');

        // A refusal lists every voucher that failed and why (nothing was sent); the dialog stays open to show it.
        if ($this->getErrorBag()->isEmpty()) {
            $this->showSend = false;
            $this->dispatch('toast', type: 'success', message: 'The batch was sent to Audit.');
        }

        $this->refresh();
    }

    public function openCancel(): void
    {
        $this->guard('finance.create_pv');
        $this->cancelReason = '';
        $this->resetErrorBag();
        $this->showCancel = true;
    }

    public function confirmCancel(): void
    {
        $this->guard('finance.create_pv');
        $this->run(fn () => $this->batchService()->cancel($this->actor(), $this->batchId, $this->cancelReason), 'cancel');

        if ($this->getErrorBag()->isEmpty()) {
            $this->showCancel = false;
            $this->dispatch('toast', type: 'success', message: 'The batch was cancelled and its vouchers are free again.');
        }

        $this->refresh();
    }

    // ------------------------------------------------------------------ after Audit

    public function acknowledgeAll(): void
    {
        $this->guard('finance.acknowledge');
        $this->resetErrorBag();
        $this->bulk = $this->run(fn () => $this->batchService()->financeAcknowledgeAll($this->actor(), $this->batchId), 'action');

        if ($this->bulk !== null) {
            $this->dispatch('toast', type: 'success', message: $this->bulk['acknowledged'].' acknowledged.');
        }
    }

    public function render(PvCatalogService $catalogue)
    {
        $batch = $this->batchOrFail($this->batchId, FinanceScope::AS_FINANCE);
        $batch->load(['creator:id,full_name', 'sender:id,full_name', 'district:id,district_name', 'events.actor:id,full_name', 'events.toUser:id,full_name', 'events.pv:id,pv_no']);

        $members = $batch->vouchers()->with(['events.actor', 'category', 'type', 'assignedTo:id,full_name'])->get();
        $catalogue->labelPvs($members->all());
        $batch->setRelation('vouchers', $members);
        $state = $batch->state();
        $canManage = $batch->isDraft() && $this->can('finance.create_pv');

        $candidates = collect();

        if ($this->showAdd && $canManage) {
            // Drafts of THIS location that are in no batch, newest first. The service re-checks every id it is given.
            $candidates = $this->scope()->atLocation($this->pvs(FinanceScope::AS_FINANCE), ['region_id' => $batch->region_id, 'district_id' => $batch->district_id])
                ->where('fin_pvs.status', FinPaymentVoucher::DRAFT)->whereNull('fin_pvs.batch_id')
                ->orderByRaw('DATE(fin_pvs.pv_date) desc')->orderByDesc('fin_pvs.id')->limit(50)->get();
            $catalogue->labelPvs($candidates->all());
        }

        return view('livewire.finance.batch-show', [
            'batch' => $batch,
            'members' => $members,
            'state' => $state,
            'total' => Money::format((int) $members->sum('amount')),
            'canManage' => $canManage,
            'canSend' => $canManage && $members->isNotEmpty(),
            'canAcknowledgeAll' => $batch->isSent() && $this->can('finance.acknowledge') && $members->contains('status', FinPaymentVoucher::AUDIT_ACKNOWLEDGED),
            'waitingToAcknowledge' => $members->where('status', FinPaymentVoucher::AUDIT_ACKNOWLEDGED)->count(),
            'candidates' => $candidates,
            'counts' => $members->countBy('status'),
            'cap' => (int) config('gwl.fin_batch_max_vouchers'),
        ]);
    }
}
