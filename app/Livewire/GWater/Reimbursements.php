<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Services\GWater\GwaterSummaryService;
use App\Services\GWater\GwaterWorkflowService;
use Livewire\Component;

/**
 * Fuel staff paid out of pocket, waiting to be paid back, per person with totals; and the ones already paid. Marking one
 * reimbursed needs gwater.reimburse and never the person who recorded the entry (or paid the fuel): the service enforces it, the
 * screen only explains it. Only fuel is reimbursable; loading and offloading are paid in cash.
 */
class Reimbursements extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;

    public ?string $payingKind = null;

    public ?int $payingId = null;

    public array $form = ['reference' => '', 'paid_on' => ''];

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.view');

        $this->form['paid_on'] = today()->toDateString();
    }

    public function openPay(string $kind, int $id): void
    {
        $this->guard('gwater.reimburse');
        abort_unless(in_array($kind, ['expense', 'trip'], true), 404);

        $this->payingKind = $kind;
        $this->payingId = $id;
        $this->form['reference'] = '';
        $this->resetErrorBag();
    }

    public function cancelPay(): void
    {
        $this->payingKind = null;
        $this->payingId = null;
    }

    public function pay(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.reimburse');
        abort_unless($this->payingKind && $this->payingId, 422);

        $this->run(fn () => $workflow->markReimbursed($this->actor(), $this->payingKind, $this->payingId, (string) $this->form['reference'], $this->form['paid_on'] ?: null), 'form');

        $this->cancelPay();
        $this->dispatch('toast', type: 'success', message: 'Marked as reimbursed.');
    }

    public function render(GwaterSummaryService $summary)
    {
        $location = $this->workingLocation();
        $pending = $location ? $summary->pendingReimbursements($location) : collect();

        return view('livewire.gwater.reimbursements', [
            'pending' => $pending,
            'byPerson' => $pending->groupBy('person'),
            'paid' => $location ? $summary->reimbursed($location) : collect(),
            'canReimburse' => $this->visibility()->can($this->actor(), 'gwater.reimburse'),
            'me' => $this->actor()->id,
        ] + $this->locationView());
    }
}
