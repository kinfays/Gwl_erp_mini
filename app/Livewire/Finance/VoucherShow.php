<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use Livewire\Component;

/**
 * One voucher as Finance sees it: the marks, the timeline by round, and Finance's actions (send, resubmit, acknowledge, void).
 * Keeps only the voucher id and the version it opened; the voucher is re-resolved inside the user's reach on every call.
 */
class VoucherShow extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;

    public int $pvId;

    public int $version = 0;

    public bool $showVoid = false;

    public string $voidReason = '';

    public string $note = '';

    public function mount(int $pv): void
    {
        $this->enforceFinance(Permission::MODULE_FINANCE);
        $this->guard('finance.view');

        $voucher = $this->pvOrFail($pv, FinanceScope::AS_FINANCE);
        $this->pvId = $voucher->id;
        $this->version = $voucher->lock_version;
    }

    public function submit(): void
    {
        $this->guard('finance.create_pv');
        $this->run(fn () => $this->workflow()->submit($this->actor(), $this->pvId, $this->version), 'action');
        $this->afterAction('Sent to Audit.');
    }

    public function resubmit(): void
    {
        $this->guard('finance.create_pv');
        $this->run(fn () => $this->workflow()->resubmit($this->actor(), $this->pvId, $this->version, $this->note), 'action');
        $this->note = '';
        $this->afterAction('Resubmitted to Audit.');
    }

    public function acknowledge(): void
    {
        $this->guard('finance.acknowledge');
        $this->run(fn () => $this->workflow()->financeAcknowledge($this->actor(), $this->pvId), 'action');
        $this->afterAction('Acknowledged. The voucher is complete.');
    }

    public function openVoid(): void
    {
        $this->guard('finance.void_pv');
        $this->voidReason = '';
        $this->resetErrorBag();
        $this->showVoid = true;
    }

    public function confirmVoid(): void
    {
        $this->guard('finance.void_pv');
        $this->run(fn () => $this->workflow()->void($this->actor(), $this->pvId, $this->voidReason), 'void');
        $this->showVoid = false;
        $this->afterAction('Voucher voided.');
    }

    protected function afterAction(string $message): void
    {
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $this->version = $this->pvOrFail($this->pvId, FinanceScope::AS_FINANCE)->lock_version;
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function render()
    {
        $pv = $this->pvOrFail($this->pvId, FinanceScope::AS_FINANCE);
        $pv->load(['events.actor', 'events.toUser:id,full_name', 'category', 'type', 'attachments', 'creator', 'batch:id,batch_no,title', 'assignedTo:id,full_name', 'assignedBy:id,full_name']);

        return view('livewire.finance.voucher-show', [
            'pv' => $pv,
            'canSubmit' => $pv->isDraft() && $this->can('finance.create_pv'),
            'canEdit' => $pv->isEditable() && $this->can('finance.create_pv'),
            'canResubmit' => $pv->status === $pv::QUERIED && $this->can('finance.create_pv'),
            'canAcknowledge' => $pv->status === $pv::AUDIT_ACKNOWLEDGED && $this->can('finance.acknowledge'),
            'canVoid' => $pv->isVoidable() && $this->can('finance.void_pv'),
            'attachmentRoute' => 'finance.pvs.attachment',
            'batchRoute' => 'finance.batches.show',
            'payeeWarning' => app(\App\Services\Finance\PayeeStatusService::class)->warningFor($pv),
        ]);
    }
}
