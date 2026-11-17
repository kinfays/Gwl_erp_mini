<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialImportBatch;
use App\Models\Permission;
use App\Models\Region;
use App\Services\Commercial\BatchResolutionService;
use App\Services\Commercial\BillingSummaryFromDetails;
use App\Services\Commercial\CommercialImportException;
use App\Services\Commercial\CommercialImportService;
use App\Services\Commercial\UploadReminderService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Batches extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;
    use WithFileUploads;
    use WithPagination;

    public ?TemporaryUploadedFile $file = null;

    public bool $showUpload = false;

    public array $preview = [];

    public string $notes = '';

    public ?int $aliasRegionId = null;

    public string $typeFilter = '';

    public string $statusFilter = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardBatchWork();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openUpload(): void
    {
        $this->guardCommercialPermission('commercial.upload_reports');
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

    public function previewFile(CommercialImportService $imports): void
    {
        $this->guardCommercialPermission('commercial.upload_reports');

        $this->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:'.((int) config('gwl.commercial_import_max_mb', 10) * 1024)],
        ]);

        $this->runPreview($imports);

        $this->dispatch(
            'toast',
            type: ! empty($this->preview['blocked']) ? 'error' : 'success',
            message: ! empty($this->preview['blocked']) ? 'The file cannot be imported yet; see the issues below.' : 'File read: '.($this->preview['total_rows'] ?? 0).' rows.'
        );
    }

    /**
     * A report whose REGION line matched nothing is blocked (the region decides who may see it). Mapping it once to a real
     * region remembers it for every later upload.
     */
    public function saveRegionAlias(BatchResolutionService $resolution, CommercialImportService $imports): void
    {
        $this->guardCommercialPermission('commercial.resolve_matches');

        $raw = $this->preview['unresolved_region'] ?? null;

        if (! $raw || ! $this->file) {
            return;
        }

        $this->validate(['aliasRegionId' => ['required', 'integer', 'exists:regions,id']]);

        // A regional user may only point a report at their own region.
        $restriction = $this->uploadRegionRestriction();

        if ($restriction !== null && (int) $this->aliasRegionId !== $restriction) {
            $this->addError('aliasRegionId', 'You can only map a report to your own region.');

            return;
        }

        $resolution->saveRegionAlias($raw, Region::query()->findOrFail($this->aliasRegionId));

        $this->aliasRegionId = null;
        $this->runPreview($imports);
        $this->dispatch('toast', type: 'success', message: 'Region remembered for future uploads.');
    }

    public function runImport(CommercialImportService $imports): void
    {
        $this->guardCommercialPermission('commercial.upload_reports');

        $this->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:'.((int) config('gwl.commercial_import_max_mb', 10) * 1024)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($this->uploadRegionRestriction() === 0) {
            $this->addError('file', 'Your account has no region assigned, so you cannot upload reports. Contact an administrator.');

            return;
        }

        // Parse and reconcile again from the uploaded file itself: the import never trusts what the browser holds.
        $result = $imports->preview($this->file, $this->uploadRegionRestriction(), $this->actorDistrictIds());
        $this->preview = Arr::except($result, ['parsed']);

        if (! empty($result['blocked'])) {
            $this->addError('file', 'Import blocked: '.($result['error_count'] ?? 0).' problem(s) must be fixed first.');

            return;
        }

        $path = $this->file->store('commercial/imports');

        try {
            $batch = $imports->createBatch(['notes' => trim($this->notes) ?: null], $result, $path ?: null, auth()->id());
        } catch (CommercialImportException $exception) {
            if ($path) {
                Storage::delete($path);
            }

            $this->addError('file', $exception->getMessage());

            return;
        }

        $this->closeUpload();
        $this->dispatch('toast', type: 'success', message: 'Batch imported with '.$batch->row_count.' rows.');

        $this->redirectRoute('commercial.batches.show', $batch, navigate: false);
    }

    protected function runPreview(CommercialImportService $imports): void
    {
        $restriction = $this->uploadRegionRestriction();

        if ($restriction === 0) {
            $this->preview = [];
            $this->addError('file', 'Your account has no region assigned, so you cannot upload reports. Contact an administrator.');

            return;
        }

        $this->preview = Arr::except($imports->preview($this->file, $restriction, $this->actorDistrictIds()), ['parsed']);
    }

    protected function resetUpload(): void
    {
        $this->resetValidation();
        $this->preview = [];
        $this->file = null;
        $this->notes = '';
        $this->aliasRegionId = null;
    }

    public function render(UploadReminderService $reminders)
    {
        // (the regional overdue list is a region-wide status: a district user is given only the route-grain reports of their own district, Phase 8f)
        $overdue = $reminders->overdueFor($this->scopeBatchesForActor(CommercialImportBatch::query())->live()->with('region')->get(), now(), $this->actorDistrictIds());

        $batches = $this->scopeUploadsForActor(CommercialImportBatch::query())
            ->with(['region', 'importer'])
            ->when($this->typeFilter !== '', fn ($query) => $query->where('report_type', $this->typeFilter))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByDesc('id')
            ->paginate(12);

        // Phase 8h: which billing summaries are stored but not counted (an uploaded and a derived one cover the same period), and whether this upload is optional
        $source = app(BillingSummaryFromDetails::class);
        $notUsed = $batches->getCollection()->filter(fn (CommercialImportBatch $b) => $b->isBilling())->mapWithKeys(fn (CommercialImportBatch $b) => [$b->id => $source->notUsedReason($b)])->filter()->all();
        $optional = config('gwl.commercial_billing_summary_preference') === 'details'
            || $this->scopeBatchesForActor(CommercialImportBatch::query())->ofType(CommercialImportBatch::TYPE_BILLING_DETAILS)->where('status', CommercialImportBatch::STATUS_IMPORTED)->exists();

        $regions = $this->uploadRegionRestriction() === null
            ? Region::query()->orderBy('region_name')->get()
            : Region::query()->whereKey($this->actorRegionId())->get();

        return view('livewire.commercial.batches', [
            'batches' => $batches,
            'types' => CommercialImportBatch::TYPES,
            'statuses' => CommercialImportBatch::ALL_STATUSES,
            'regions' => $regions,
            'canUpload' => $this->actorCan('commercial.upload_reports'),
            'canResolve' => $this->actorCan('commercial.resolve_matches'),
            'maxMb' => (int) config('gwl.commercial_import_max_mb', 10),
            'billingDetailsMaxMb' => (int) config('gwl.commercial_billing_details_import_max_mb', 250),
            'overdue' => $overdue,
            'notUsed' => $notUsed,
            'summaryUploadOptional' => $optional,
        ]);
    }
}
