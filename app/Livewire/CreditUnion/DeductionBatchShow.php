<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionDeductionBatch;
use App\Models\CreditUnionDeductionBatchLine;
use App\Models\Permission;
use App\Services\CreditUnion\DeductionImportService;
use App\Services\CreditUnion\DeductionPostingService;
use Livewire\Component;
use Livewire\WithPagination;

class DeductionBatchShow extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public CreditUnionDeductionBatch $batch;

    public string $matchFilter = '';

    public ?int $editingLineId = null;

    public string $resolutionNotes = '';

    public function mount(CreditUnionDeductionBatch $batch): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardManageDeductions();

        $this->batch = $batch;
    }

    public function updatedMatchFilter(): void
    {
        $this->resetPage();
    }

    public function postBatch(DeductionPostingService $posting): void
    {
        $this->guardManageDeductions();

        $posting->post($this->batch, auth()->id());

        $this->batch->refresh();
        $this->dispatch('toast', type: 'success', message: 'Batch posted. Status: '.$this->batch->status.'.');
    }

    public function startResolving(int $lineId): void
    {
        $this->guardManageDeductions();

        $line = $this->lineOrFail($lineId);

        $this->editingLineId = $line->id;
        $this->resolutionNotes = (string) $line->resolution_notes;
    }

    public function cancelResolving(): void
    {
        $this->editingLineId = null;
        $this->resolutionNotes = '';
    }

    public function saveResolution(): void
    {
        $this->guardManageDeductions();

        $this->validate([
            'resolutionNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        $line = $this->lineOrFail((int) $this->editingLineId);
        $line->update(['resolution_notes' => trim($this->resolutionNotes) ?: null]);

        $this->cancelResolving();
        $this->dispatch('toast', type: 'success', message: 'Resolution note saved.');
    }

    /**
     * Re-runs matching for one line after the underlying member data has been corrected.
     */
    public function rematchLine(int $lineId, DeductionImportService $imports): void
    {
        $this->guardManageDeductions();

        if ($this->batch->hasBeenPosted()) {
            $this->addError('lines', 'This batch has already been posted; lines can no longer be rematched.');

            return;
        }

        $line = $imports->rematchLine($this->lineOrFail($lineId));

        $this->dispatch('toast', type: 'success', message: 'Line rematched as '.str_replace('_', ' ', $line->match_status).'.');
    }

    protected function lineOrFail(int $lineId): CreditUnionDeductionBatchLine
    {
        return $this->batch->lines()->whereKey($lineId)->firstOrFail();
    }

    protected function guardManageDeductions(): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin') && ! $user->hasPermission('credit_union.manage_deductions'))) {
            abort(403);
        }
    }

    public function render()
    {
        $lines = $this->batch->lines()
            ->with('member')
            ->when($this->matchFilter !== '', fn ($query) => $query->where('match_status', $this->matchFilter))
            ->orderByRaw("case when match_status = 'matched' then 1 else 0 end")
            ->orderBy('staff_id_raw')
            ->paginate(20);

        return view('livewire.credit-union.deduction-batch-show', [
            'lines' => $lines,
            'reconciliation' => app(DeductionPostingService::class)->reconciliation($this->batch),
            'matchStatuses' => CreditUnionDeductionBatchLine::MATCH_STATUSES,
        ]);
    }
}
