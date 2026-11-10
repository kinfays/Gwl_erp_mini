<?php

namespace App\Livewire\Finance\Concerns;

use App\Services\Finance\FinanceScope;
use App\Services\Finance\PrintSelection;
use App\Services\Finance\PvPrintService;

/**
 * "Print selected" for a voucher list (Finance's list, Audit's My work): tick vouchers in the print column, prepare, then open the PDF
 * from the link. The ticks are only a request: the ids are re-read inside the user's reach (a tampered or out-of-reach id drops out),
 * checked against the print limit and handed to the print route through a one-use token (PrintSelection), never in the URL. The print
 * route checks permission and reach again. A link rather than an automatic pop-up, so no browser blocks it.
 *
 * The using component says which door it is (printAs) and provides pvs() (ScopesFinanceByActor).
 */
trait PreparesPrint
{
    /** @var array<int, string> the vouchers ticked for printing */
    public array $printIds = [];

    public ?string $printUrl = null;

    public int $printCount = 0;

    abstract protected function printAs(): string;

    public function updatedPrintIds(): void
    {
        $this->printUrl = null;
    }

    public function preparePrint(): void
    {
        $as = $this->printAs();
        app(PvPrintService::class)->authorise($this->actor(), $as);
        $this->resetErrorBag('print');
        $this->printUrl = null;

        $ids = array_values(array_unique(array_filter(array_map('intval', $this->printIds))));
        $found = $ids === [] ? [] : $this->pvs($as)->whereIn('fin_pvs.id', $ids)->pluck('fin_pvs.id')->map(fn ($id) => (int) $id)->all();

        if ($found === []) {
            $this->addError('print', 'Tick at least one voucher to print.');

            return;
        }

        $cap = app(PvPrintService::class)->cap();

        if (count($found) > $cap) {
            $this->addError('print', 'You can print at most '.$cap.' vouchers at a time (you ticked '.count($found).').');

            return;
        }

        $token = app(PrintSelection::class)->stash($this->actor(), $as, $found);
        $this->printCount = count($found);
        $this->printUrl = route($as === FinanceScope::AS_AUDIT ? 'audit.print-selected' : 'finance.print-selected', $token);
    }

    public function clearPrint(): void
    {
        $this->reset(['printIds', 'printUrl', 'printCount']);
        $this->resetErrorBag('print');
    }
}
