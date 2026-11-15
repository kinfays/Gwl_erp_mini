<?php

namespace App\Livewire\Commercial\Customers;

use App\Jobs\Commercial\ProcessCustomerListBatch;
use App\Jobs\Commercial\VoidCustomerListUpload;
use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialCustomerBatch;
use App\Models\CommercialCustomerUpload;
use App\Models\District;
use App\Models\Permission;
use App\Models\Region;
use App\Services\Commercial\Customers\CustomerBatchLifecycle;
use App\Services\Commercial\Customers\CustomerImportException;
use App\Services\Commercial\Customers\CustomerImportService;
use App\Services\Commercial\LocationMatcher;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One uploaded customer-list FILE (Phase 8b): the districts the first pass found in it with their customer and route counts, the check
 * before anything is loaded (untick a district to leave it out), the district names that need a match, every district's own batch and
 * "undo this upload". Thin: the rules are CustomerImportService / CustomerBatchLifecycle; a district user is shown their own district only.
 */
class UploadShow extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    #[Locked]
    public int $uploadId;

    /** @var list<int> the district ids ticked at the check */
    public array $selected = [];

    /** @var array<string, int|string> unrecognised label => the district chosen for it */
    public array $matches = [];

    public ?int $matchRegionId = null;

    public bool $confirmingVoid = false;

    public string $voidReason = '';

    public function mount(CommercialCustomerUpload $upload): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCustomerList();
        $this->guardCommercialPermission('commercial.upload_reports', 'commercial.resolve_matches', 'commercial.void_batches');

        $this->uploadId = $upload->id;
        abort_unless($this->canSee($upload), 404);

        // everything the first pass found is ticked: the person unticks what they do not want to update
        $this->selected = collect($upload->districts ?? [])->where('state', 'load')->pluck('district_id')->map(fn ($id) => (int) $id)->all();
    }

    protected function upload(): CommercialCustomerUpload
    {
        $upload = CommercialCustomerUpload::query()->findOrFail($this->uploadId);
        abort_unless($this->canSee($upload), 404);

        return $upload;
    }

    /** The viewer's region's uploads, their own that have no region yet, and (a district user) uploads that have a batch in their district. */
    protected function canSee(CommercialCustomerUpload $upload): bool
    {
        $restriction = $this->customerRestriction();
        $districtIds = $this->customerDistrictIds();

        if ($districtIds !== null) {
            return (int) $upload->imported_by === (int) auth()->id()
                || ($districtIds !== [] && $upload->batches()->whereIn('district_id', $districtIds)->exists());
        }

        return $restriction === null
            || ($restriction !== 0 && $upload->region_id !== null && (int) $upload->region_id === $restriction)
            || ($upload->region_id === null && (int) $upload->imported_by === (int) auth()->id());
    }

    /** The batches of the upload the viewer may see (a district user: their own district's). */
    protected function visibleBatches(CommercialCustomerUpload $upload)
    {
        $districtIds = $this->customerDistrictIds();

        return $upload->batches()->with('district')
            ->when($districtIds !== null, fn ($q) => $districtIds === [] ? $q->whereRaw('1 = 0') : $q->whereIn('district_id', $districtIds))->get();
    }

    protected function dispatchFirst(CommercialCustomerUpload $upload): void
    {
        $first = $upload->batches()->first();

        if ($first) {
            ProcessCustomerListBatch::dispatch($first->id);
        }
    }

    // ---------------------------------------------------------------- the check before loading

    public function confirmDistricts(CustomerImportService $imports): void
    {
        $this->guardCommercialPermission('commercial.upload_reports');
        $upload = $this->upload();

        if ($upload->status !== CommercialCustomerUpload::STATUS_AWAITING_REVIEW) {
            return;
        }

        $offered = collect($upload->districts ?? [])->where('state', 'load')->pluck('district_id')->map(fn ($id) => (int) $id)->all();
        $chosen = array_values(array_intersect($offered, array_map('intval', $this->selected)));

        try {
            $imports->confirm($upload, $chosen);
        } catch (CustomerImportException $exception) {
            $this->addError('selected', $exception->getMessage());

            return;
        }

        $this->dispatchFirst($upload->refresh());
        $this->dispatch('toast', type: 'success', message: $chosen === [] ? 'Nothing was chosen, so nothing is loaded.' : 'Loading '.count($chosen).' '.(count($chosen) === 1 ? 'district' : 'districts').'.');
    }

    public function cancelUpload(CustomerImportService $imports): void
    {
        $this->guardCommercialPermission('commercial.upload_reports');

        try {
            $imports->cancel($this->upload());
        } catch (CustomerImportException $exception) {
            $this->addError('selected', $exception->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: 'Cancelled. Nothing was loaded and the file was deleted.');
    }

    // ---------------------------------------------------------------- names that need a match

    public function matchRegion(LocationMatcher $matcher, CustomerImportService $imports): void
    {
        $this->guardCommercialPermission('commercial.resolve_matches');
        $upload = $this->upload();
        $this->validate(['matchRegionId' => ['required', 'integer', 'exists:regions,id']]);

        $restriction = $this->customerRestriction();

        if ($restriction !== null && (int) $this->matchRegionId !== $restriction) {
            $this->addError('matchRegionId', 'You can only match a file to your own region.');

            return;
        }

        if (! $upload->region_label_raw || $upload->status !== CommercialCustomerUpload::STATUS_NEEDS_MATCH || $upload->region_id !== null) {
            return;
        }

        $matcher->saveRegionAlias($upload->region_label_raw, Region::query()->findOrFail($this->matchRegionId));
        $imports->resumeAfterMatch($upload);
        $this->dispatchFirst($upload);
        $this->dispatch('toast', type: 'success', message: 'Region remembered. The file is being read.');
    }

    /** Matches every unrecognised district name at once (the answers are remembered for every later upload). */
    public function matchDistricts(LocationMatcher $matcher, CustomerImportService $imports): void
    {
        $this->guardCommercialPermission('commercial.resolve_matches');
        $upload = $this->upload();

        if ($upload->status !== CommercialCustomerUpload::STATUS_NEEDS_MATCH || ! $upload->region_id) {
            return;
        }

        $unrecognised = collect($upload->unrecognised ?? []);
        $chosen = [];

        foreach ($unrecognised as $index => $entry) {
            $districtId = (int) ($this->matches[$index] ?? 0);
            $district = $districtId > 0 ? District::query()->find($districtId) : null;

            if (! $district || (int) $district->region_id !== (int) $upload->region_id) {
                $this->addError('matches.'.$index, 'Pick a district of '.($upload->region?->region_name ?? 'this region').'.');

                continue;
            }

            $chosen[(string) $entry['label']] = $district;
        }

        if (count($chosen) !== $unrecognised->count()) {
            return;
        }

        foreach ($chosen as $label => $district) {
            $matcher->saveDistrictAlias($label, $district);
        }

        $imports->resumeAfterMatch($upload);
        $this->dispatchFirst($upload);
        $this->matches = [];
        $this->dispatch('toast', type: 'success', message: 'Districts remembered. The file is being read.');
    }

    // ---------------------------------------------------------------- retry and undo

    public function retry(): void
    {
        $this->guardCommercialPermission('commercial.upload_reports');
        $upload = $this->upload();

        $runnable = $upload->batches()->where('status', CommercialCustomerBatch::STATUS_FAILED)->get();

        if ($runnable->isEmpty()) {
            return;
        }

        foreach ($runnable as $batch) {
            $batch->forceFill(['status' => CommercialCustomerBatch::STATUS_QUEUED, 'error_message' => null])->save();
        }

        $upload->forceFill(['status' => CommercialCustomerUpload::STATUS_QUEUED, 'error_message' => null])->save();
        ProcessCustomerListBatch::dispatch($runnable->first()->id);
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

    public function voidUpload(CustomerBatchLifecycle $lifecycle): void
    {
        $this->guardCommercialPermission('commercial.void_batches');
        $upload = $this->upload();
        $this->validate(['voidReason' => ['required', 'string', 'min:3', 'max:500']]);

        // only the batches the viewer may see are undone by them: a district user cannot undo another district's batch
        $undoable = $this->visibleBatches($upload)->filter(fn (CommercialCustomerBatch $b) => $lifecycle->voidBlocker($b) === null);

        if ($undoable->isEmpty()) {
            $this->addError('voidReason', 'No district of this upload is still the newest of its district, so none of it can be undone exactly. Upload a corrected file instead.');

            return;
        }

        foreach ($undoable as $batch) {
            $batch->forceFill(['phase' => 'voiding', 'error_message' => null])->save();
        }

        // ONE job undoes every batch of the upload that is still the newest of its district (and the viewer's own district, for a district user)
        VoidCustomerListUpload::dispatch($upload->id, (int) auth()->id(), $this->voidReason, $this->customerDistrictIds());

        $upload->forceFill(['status' => CommercialCustomerUpload::STATUS_PROCESSING])->save();
        $this->cancelVoid();
        $this->dispatch('toast', type: 'success', message: 'Undoing '.$undoable->count().' '.($undoable->count() === 1 ? 'district' : 'districts').': the data is being put back as it was.');
    }

    public function render(CustomerBatchLifecycle $lifecycle, CustomerImportService $imports)
    {
        $upload = $this->upload()->load('region', 'importer');
        $batches = $this->visibleBatches($upload);
        $districtLimited = $this->actorDistrictLimited();
        $restriction = $this->customerRestriction();

        // a finished background undo settles the upload's own status
        if ($batches->isNotEmpty() && ! $batches->contains(fn (CommercialCustomerBatch $b) => $b->phase === 'voiding') && $upload->status === CommercialCustomerUpload::STATUS_PROCESSING && $upload->phase === CommercialCustomerUpload::PHASE_DONE) {
            $imports->settle($upload);
            $upload->refresh();
        }

        return view('livewire.commercial.customers.upload-show', [
            'upload' => $upload,
            'batches' => $batches,
            'working' => $upload->isWorking() || $batches->contains(fn (CommercialCustomerBatch $b) => $b->phase === 'voiding'),
            // a district user is told how many were skipped, never which
            'entries' => collect($upload->districts ?? [])->when($districtLimited, fn ($c) => $c->reject(fn ($e) => ($e['state'] ?? '') === 'not_yours'))->values()->all(),
            'skippedNotYours' => (int) collect($upload->districts ?? [])->where('state', 'not_yours')->count(),
            'flagged' => $districtLimited ? [] : $imports->flaggedRoutes($upload),
            'regions' => $restriction === null ? Region::query()->orderBy('region_name')->get() : Region::query()->whereKey($restriction)->get(),
            'districts' => $upload->region_id ? District::query()->where('region_id', $upload->region_id)->orderBy('district_name')->get() : collect(),
            'canUpload' => $this->actorCan('commercial.upload_reports'),
            'canResolve' => $this->actorCan('commercial.resolve_matches'),
            'canVoid' => $this->actorCan('commercial.void_batches'),
            'undoable' => $batches->filter(fn (CommercialCustomerBatch $b) => $b->isLive() && $b->phase !== 'voiding' && $lifecycle->voidBlocker($b) === null)->count(),
            'progress' => $upload->progressPercent($batches),
        ]);
    }
}
