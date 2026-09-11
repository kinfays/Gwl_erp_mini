<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionDeductionBatch;
use App\Models\Permission;
use App\Services\CreditUnion\DeductionImportService;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Deductions extends Component
{
    use EnforcesModuleAccess;
    use WithFileUploads;
    use WithPagination;

    public ?TemporaryUploadedFile $file = null;

    public string $statusFilter = '';

    public bool $showUpload = false;

    public array $preview = [];

    public array $form = [
        'period_month' => '',
        'bank_reference' => '',
        'banked_date' => '',
        'amount_received' => '',
        'notes' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardManageDeductions();

        $this->form['period_month'] = today()->startOfMonth()->toDateString();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openUpload(): void
    {
        $this->guardManageDeductions();
        $this->resetUpload();
        $this->showUpload = true;
    }

    public function closeUpload(): void
    {
        $this->resetUpload();
        $this->showUpload = false;
    }

    public function clearPreview(): void
    {
        $this->preview = [];
        $this->file = null;
    }

    public function previewFile(DeductionImportService $imports): void
    {
        $this->guardManageDeductions();

        $this->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt'],
        ]);

        $this->preview = $imports->preview($this->file);

        $this->dispatch(
            'toast',
            type: $this->preview['blocked'] ? 'error' : 'success',
            message: $this->preview['valid_count'].' of '.$this->preview['total_rows'].' rows read.'
        );
    }

    public function runImport(DeductionImportService $imports): void
    {
        $this->guardManageDeductions();

        $this->validate([
            'form.period_month' => ['required', 'date'],
            'form.bank_reference' => ['nullable', 'string', 'max:100'],
            'form.banked_date' => ['nullable', 'date'],
            'form.amount_received' => ['required', 'numeric', 'min:0'],
            'form.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (empty($this->preview['valid_rows'])) {
            $this->addError('file', 'Preview a deduction file with at least one usable row first.');

            return;
        }

        if (! empty($this->preview['blocked'])) {
            $this->addError('file', sprintf(
                'Import blocked because %.1f%% of rows could not be posted. The configured maximum is %d%%.',
                $this->preview['failure_percent'] ?? 0,
                $this->preview['max_failure_percent'] ?? config('gwl.max_import_failure_percent', 20)
            ));

            return;
        }

        $path = $this->file?->store('credit-union/deductions');

        $batch = $imports->createBatch(
            $this->form,
            $this->preview['valid_rows'],
            $path ?: null,
            auth()->id()
        );

        $this->closeUpload();
        $this->dispatch('toast', type: 'success', message: 'Deduction batch imported with '.$batch->lines()->count().' lines.');

        $this->redirectRoute('credit-union.deductions.show', $batch, navigate: false);
    }

    protected function resetUpload(): void
    {
        $this->resetValidation();
        $this->preview = [];
        $this->file = null;
        $this->form = [
            'period_month' => today()->startOfMonth()->toDateString(),
            'bank_reference' => '',
            'banked_date' => '',
            'amount_received' => '',
            'notes' => '',
        ];
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
        $batches = CreditUnionDeductionBatch::query()
            ->withCount('lines')
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->paginate(12);

        return view('livewire.credit-union.deductions', [
            'batches' => $batches,
            'statuses' => CreditUnionDeductionBatch::STATUSES,
            'expectedHeadings' => DeductionImportService::HEADINGS,
        ]);
    }
}
