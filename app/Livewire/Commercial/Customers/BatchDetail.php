<?php

namespace App\Livewire\Commercial\Customers;

use App\Jobs\Commercial\ProcessCustomerListBatch;
use App\Jobs\Commercial\VoidCustomerListBatch;
use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialCustomerBatch;
use App\Models\District;
use App\Models\Permission;
use App\Models\Region;
use App\Services\Commercial\Customers\CustomerBatchLifecycle;
use App\Services\Commercial\Customers\CustomerImportService;
use App\Services\Commercial\LocationMatcher;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One customer-list batch: its progress, what the check against the file's own totals found, what the merge did, and the
 * actions that apply (match a region or district, run it again, void it). Polls while the batch is being worked on.
 */
class BatchDetail extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    #[Locked]
    public int $batchId;

    public ?int $matchRegionId = null;

    public ?int $matchDistrictId = null;

    public bool $confirmingVoid = false;

    public string $voidReason = '';

    public function mount(CommercialCustomerBatch $batch): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCustomerList();
        $this->guardCommercialPermission('commercial.upload_reports', 'commercial.resolve_matches', 'commercial.void_batches');

        $this->batchId = $batch->id;
        abort_unless($this->canSee($batch), 404);
    }

    protected function batch(): CommercialCustomerBatch
    {
        $batch = CommercialCustomerBatch::query()->findOrFail($this->batchId);
        abort_unless($this->canSee($batch), 404);

        return $batch;
    }

    /** The viewer's region's batches, and their own uploads that have no region yet. Another region's batch answers 404. */
    protected function canSee(CommercialCustomerBatch $batch): bool
    {
        $restriction = $this->customerRestriction();

        return $restriction === null
            || ($restriction !== 0 && $batch->region_id !== null && (int) $batch->region_id === $restriction)
            || ($batch->region_id === null && (int) $batch->imported_by === (int) auth()->id());
    }

    public function matchRegion(LocationMatcher $matcher, CustomerImportService $imports): void
    {
        $this->guardCommercialPermission('commercial.resolve_matches');
        $batch = $this->batch();
        $this->validate(['matchRegionId' => ['required', 'integer', 'exists:regions,id']]);

        $restriction = $this->customerRestriction();

        if ($restriction !== null && (int) $this->matchRegionId !== $restriction) {
            $this->addError('matchRegionId', 'You can only match a file to your own region.');

            return;
        }

        if (! $batch->region_label_raw || $batch->status !== CommercialCustomerBatch::STATUS_NEEDS_MATCH || $batch->region_id !== null) {
            return;
        }

        $matcher->saveRegionAlias($batch->region_label_raw, Region::query()->findOrFail($this->matchRegionId));
        $this->resume($batch, $imports);
        $this->dispatch('toast', type: 'success', message: 'Region remembered. The file is being read.');
    }

    public function matchDistrict(LocationMatcher $matcher, CustomerImportService $imports): void
    {
        $this->guardCommercialPermission('commercial.resolve_matches');
        $batch = $this->batch();
        $this->validate(['matchDistrictId' => ['required', 'integer', 'exists:districts,id']]);

        $district = District::query()->findOrFail($this->matchDistrictId);

        if ($batch->status !== CommercialCustomerBatch::STATUS_NEEDS_MATCH || ! $batch->region_id || (int) $district->region_id !== (int) $batch->region_id) {
            $this->addError('matchDistrictId', 'Pick a district of '.($batch->region?->region_name ?? 'this region').'.');

            return;
        }

        $matcher->saveDistrictAlias((string) $batch->district_label_raw, $district);
        $this->resume($batch, $imports);
        $this->dispatch('toast', type: 'success', message: 'District remembered. The file is being read.');
    }

    protected function resume(CommercialCustomerBatch $batch, CustomerImportService $imports): void
    {
        $imports->resumeAfterMatch($batch);
        ProcessCustomerListBatch::dispatch($batch->id);
    }

    public function retry(): void
    {
        $this->guardCommercialPermission('commercial.upload_reports');
        $batch = $this->batch();

        if ($batch->status !== CommercialCustomerBatch::STATUS_FAILED) {
            return;
        }

        $batch->forceFill(['status' => CommercialCustomerBatch::STATUS_QUEUED, 'error_message' => null])->save();
        ProcessCustomerListBatch::dispatch($batch->id);
        $this->dispatch('toast', type: 'success', message: 'Running again from where it stopped.');
    }

    public function startVoid(): void
    {
        $this->guardCommercialPermission('commercial.void_batches');
        $this->confirmingVoid = true;
    }

    public function cancelVoid(): void
    {
        $this->confirmingVoid = false;
        $this->voidReason = '';
    }

    public function voidBatch(CustomerBatchLifecycle $lifecycle): void
    {
        $this->guardCommercialPermission('commercial.void_batches');
        $batch = $this->batch();
        $this->validate(['voidReason' => ['required', 'string', 'min:3', 'max:500']]);

        if ($blocker = $lifecycle->voidBlocker($batch)) {
            $this->addError('voidReason', $blocker);

            return;
        }

        $batch->forceFill(['phase' => 'voiding', 'error_message' => null])->save();
        VoidCustomerListBatch::dispatch($batch->id, (int) auth()->id(), $this->voidReason);

        $this->cancelVoid();
        $this->dispatch('toast', type: 'success', message: 'Voiding: the data is being put back as it was.');
    }

    public function render(CustomerBatchLifecycle $lifecycle)
    {
        $batch = $this->batch()->load(['district', 'region', 'importer']);
        $restriction = $this->customerRestriction();

        return view('livewire.commercial.customers.batch-detail', [
            'batch' => $batch,
            'working' => $batch->isWorking() || $batch->phase === 'voiding',
            'voiding' => $batch->phase === 'voiding',
            'regions' => $restriction === null ? Region::query()->orderBy('region_name')->get() : Region::query()->whereKey($restriction)->get(),
            'districts' => $batch->region_id ? District::query()->where('region_id', $batch->region_id)->orderBy('district_name')->get() : collect(),
            'canResolve' => $this->actorCan('commercial.resolve_matches'),
            'canVoid' => $this->actorCan('commercial.void_batches'),
            'canUpload' => $this->actorCan('commercial.upload_reports'),
            'voidBlocker' => $batch->isLive() && $batch->phase !== 'voiding' ? $lifecycle->voidBlocker($batch) : null,
        ]);
    }
}
