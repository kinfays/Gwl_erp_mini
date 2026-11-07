<?php

namespace App\Livewire\Audit;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\FinPaymentVoucher;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use Livewire\Component;

/**
 * One voucher as Audit sees it (never a draft): tick it, query it (X, with a reason code and a comment) or withdraw a tick
 * while Finance has not acknowledged. The person who sent the voucher to Audit is never offered these.
 */
class VoucherShow extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;

    public int $pvId;

    public bool $showQuery = false;

    public bool $showWithdraw = false;

    public string $reasonCode = '';

    public string $comment = '';

    public string $withdrawReason = '';

    public function mount(int $pv): void
    {
        $this->enforceFinance(Permission::MODULE_AUDIT);
        $this->guard('audit.view');

        $this->pvId = $this->pvOrFail($pv, FinanceScope::AS_AUDIT)->id;
    }

    public function tick(): void
    {
        $this->guard('audit.review');
        $this->run(fn () => $this->workflow()->auditAcknowledge($this->actor(), $this->pvId), 'action');
        $this->afterAction('Acknowledged.');
    }

    public function openQuery(): void
    {
        $this->guard('audit.review');
        $this->reasonCode = '';
        $this->comment = '';
        $this->resetErrorBag();
        $this->showQuery = true;
    }

    public function confirmQuery(): void
    {
        $this->guard('audit.review');
        $this->run(fn () => $this->workflow()->auditQuery($this->actor(), $this->pvId, $this->reasonCode, $this->comment), 'query');
        $this->showQuery = false;
        $this->afterAction('Voucher queried. Finance has been told.');
    }

    public function openWithdraw(): void
    {
        $this->guard('audit.review');
        $this->withdrawReason = '';
        $this->resetErrorBag();
        $this->showWithdraw = true;
    }

    public function confirmWithdraw(): void
    {
        $this->guard('audit.review');
        $this->run(fn () => $this->workflow()->withdrawTick($this->actor(), $this->pvId, $this->withdrawReason), 'withdraw');
        $this->showWithdraw = false;
        $this->afterAction('Tick withdrawn. Finance has been told.');
    }

    protected function afterAction(string $message): void
    {
        if ($this->getErrorBag()->isEmpty()) {
            $this->dispatch('toast', type: 'success', message: $message);
        }
    }

    public function render()
    {
        $pv = $this->pvOrFail($this->pvId, FinanceScope::AS_AUDIT);
        $pv->load(['events.actor', 'category', 'type', 'attachments', 'creator']);

        $mine = $this->workflow()->hasSubmitted($this->actor(), $pv);
        $canReview = $this->can('audit.review') && ! $mine;

        return view('livewire.audit.voucher-show', [
            'pv' => $pv,
            'canTick' => $canReview && $pv->status === FinPaymentVoucher::WITH_AUDIT,
            'canQuery' => $canReview && $pv->status === FinPaymentVoucher::WITH_AUDIT,
            'canWithdraw' => $canReview && $pv->status === FinPaymentVoucher::AUDIT_ACKNOWLEDGED,
            'submittedByMe' => $mine && $this->can('audit.review'),
            'reasons' => FinPaymentVoucher::QUERY_REASONS,
            'attachmentRoute' => 'audit.pvs.attachment',
            'payeeWarning' => app(\App\Services\Finance\PayeeStatusService::class)->warningFor($pv),
        ]);
    }
}
