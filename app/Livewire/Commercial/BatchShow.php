<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingStat;
use App\Models\District;
use App\Models\Employee;
use App\Models\Permission;
use App\Services\Commercial\BatchLifecycleService;
use App\Services\Commercial\BatchResolutionService;
use App\Services\Commercial\CommercialImportException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class BatchShow extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;
    use WithPagination;

    public CommercialImportBatch $batch;

    public string $matchFilter = '';

    public string $routeSearch = '';

    public ?string $resolvingDistrictLabel = null;

    public ?int $resolveDistrictId = null;

    public ?string $linkingReaderId = null;

    public string $employeeSearch = '';

    public bool $confirmingVoid = false;

    public string $voidReason = '';

    public function mount(CommercialImportBatch $batch): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardBatchWork();
        $this->abortUnlessBatchAccessible($batch);

        $this->batch = $batch;
    }

    public function updatedMatchFilter(): void
    {
        $this->resetPage();
    }

    public function updatedRouteSearch(): void
    {
        $this->resetPage();
    }

    public function rematch(BatchResolutionService $resolution): void
    {
        $this->authorizeBatchAction('commercial.resolve_matches');

        $matched = $resolution->rematch($this->batch);
        $this->batch->refresh();

        $this->dispatch('toast', type: 'success', message: $matched > 0 ? "{$matched} matched this time." : 'Nothing new matched.');
    }

    public function startResolvingDistrict(string $label): void
    {
        $this->authorizeBatchAction('commercial.resolve_matches');

        abort_unless($this->batch->isBilling() && $this->batch->routes()->whereNull('district_id')->where('district_label_raw', $label)->exists(), 404);

        $this->resolvingDistrictLabel = $label;
        $this->resolveDistrictId = null;
        $this->resetValidation();
    }

    public function cancelResolvingDistrict(): void
    {
        $this->resolvingDistrictLabel = null;
        $this->resolveDistrictId = null;
    }

    public function saveDistrictResolution(BatchResolutionService $resolution): void
    {
        $this->authorizeBatchAction('commercial.resolve_matches');

        $this->validate([
            'resolveDistrictId' => ['required', 'integer', Rule::exists('districts', 'id')->where('region_id', $this->batch->region_id ?? 0)],
        ], [], ['resolveDistrictId' => 'district']);

        abort_if($this->resolvingDistrictLabel === null, 404);

        try {
            $matched = $resolution->resolveDistrict($this->batch, $this->resolvingDistrictLabel, District::query()->findOrFail($this->resolveDistrictId));
        } catch (CommercialImportException $exception) {
            $this->addError('resolveDistrictId', $exception->getMessage());

            return;
        }

        $this->batch->refresh();
        $this->cancelResolvingDistrict();
        $this->dispatch('toast', type: 'success', message: "District remembered; {$matched} routes matched.");
    }

    public function startLinkingReader(string $staffId): void
    {
        $this->authorizeBatchAction('commercial.resolve_matches');

        abort_unless($this->batch->stats()->where('reader_staff_id', $staffId)->where('match_status', CommercialReadingStat::MATCH_UNMATCHED)->exists(), 404);

        $this->linkingReaderId = $staffId;
        $this->employeeSearch = $staffId;
    }

    public function cancelLinkingReader(): void
    {
        $this->linkingReaderId = null;
        $this->employeeSearch = '';
    }

    public function linkReader(int $employeeId, BatchResolutionService $resolution): void
    {
        $this->authorizeBatchAction('commercial.resolve_matches');

        abort_if($this->linkingReaderId === null, 404);

        $employee = Employee::query()->visibleTo($this->actor())->findOrFail($employeeId);

        try {
            $rows = $resolution->linkReader($this->batch, $this->linkingReaderId, $employee);
        } catch (CommercialImportException $exception) {
            $this->addError('employeeSearch', $exception->getMessage());

            return;
        }

        $this->batch->refresh();
        $this->cancelLinkingReader();
        $this->dispatch('toast', type: 'success', message: "Reader linked to {$employee->full_name} ({$rows} rows).");
    }

    public function startVoid(): void
    {
        $this->authorizeBatchAction('commercial.void_batches');

        abort_if($this->batch->isVoided(), 404);

        $this->confirmingVoid = true;
        $this->voidReason = '';
        $this->resetValidation();
    }

    public function cancelVoid(): void
    {
        $this->confirmingVoid = false;
        $this->voidReason = '';
    }

    public function voidBatch(BatchLifecycleService $lifecycle): void
    {
        $this->authorizeBatchAction('commercial.void_batches');

        $this->validate(['voidReason' => ['required', 'string', 'min:5', 'max:2000']], [], ['voidReason' => 'reason']);

        try {
            $lifecycle->void($this->batch, $this->voidReason, auth()->id());
        } catch (CommercialImportException $exception) {
            $this->addError('voidReason', $exception->getMessage());

            return;
        }

        $this->batch->refresh();
        $this->cancelVoid();
        $this->dispatch('toast', type: 'success', message: 'Batch voided. Its rows no longer count in any analysis.');
    }

    /** Every action re-checks the permission AND that the batch is in the actor's region (Livewire calls are replayable). */
    protected function authorizeBatchAction(string $permission): void
    {
        $this->guardCommercialPermission($permission);
        $this->abortUnlessBatchAccessible($this->batch);
    }

    public function render()
    {
        $batch = $this->batch;
        $data = ['batchTypeLabel' => CommercialImportBatch::typeLabel($batch->report_type)];

        if ($batch->isReading()) {
            $data += [
                'monthSummary' => $batch->stats()->readers()
                    ->select('month', DB::raw('count(*) as readers'), DB::raw('sum(read_count) as read_total'), DB::raw('sum(skipped_count) as skipped_total'), DB::raw('sum(visited_count) as visited_total'))
                    ->groupBy('month')->orderBy('month')->get(),
                'strengths' => $batch->strengths()->orderBy('month')->pluck('verified_strength', 'month')
                    ->mapWithKeys(fn ($value, $month) => [substr((string) $month, 0, 10) => $value]),
                'systemRows' => $batch->stats()->where('match_status', CommercialReadingStat::MATCH_SYSTEM_ACCOUNT)->count(),
                'unmatchedReaders' => $batch->stats()
                    ->where('match_status', CommercialReadingStat::MATCH_UNMATCHED)
                    ->select('reader_staff_id', DB::raw('max(reader_name_raw) as reader_name'), DB::raw('count(*) as months'), DB::raw('sum(visited_count) as visited_total'))
                    ->groupBy('reader_staff_id')->orderBy('reader_staff_id')->get(),
                'rows' => $batch->stats()
                    ->with(['employee', 'district'])
                    ->when($this->matchFilter !== '', fn ($query) => $query->where('match_status', $this->matchFilter))
                    ->orderBy('reader_staff_id')->orderBy('month')
                    ->paginate(20),
                'matchStatuses' => CommercialReadingStat::MATCH_STATUSES,
                'candidates' => $this->linkingReaderId !== null && trim($this->employeeSearch) !== ''
                    ? Employee::query()->visibleTo($this->actor())
                        ->where(fn ($query) => $query->where('staff_id', trim($this->employeeSearch))->orWhere('full_name', 'like', '%'.trim($this->employeeSearch).'%'))
                        ->orderBy('full_name')->limit(8)->get()
                    : collect(),
            ];
        } else {
            $data += [
                'unmatchedDistricts' => $batch->routes()
                    ->whereNull('district_id')
                    ->select('district_label_raw', DB::raw('count(*) as route_count'))
                    ->groupBy('district_label_raw')->orderBy('district_label_raw')->get(),
                'districtOptions' => District::query()->where('region_id', $batch->region_id ?? 0)->orderBy('district_name')->get(),
                'rows' => $batch->routes()
                    ->with('district')
                    ->when(trim($this->routeSearch) !== '', fn ($query) => $query->where(fn ($inner) => $inner
                        ->where('route_code', 'like', '%'.trim($this->routeSearch).'%')
                        ->orWhere('district_label_raw', 'like', '%'.trim($this->routeSearch).'%')))
                    ->orderBy('district_label_raw')->orderBy('route_code')
                    ->paginate(20),
                'bands' => $batch->bands()->orderBy('id')->get(),
            ];
        }

        return view('livewire.commercial.batch-show', $data + [
            'canResolve' => $this->actorCan('commercial.resolve_matches'),
            'canVoid' => $this->actorCan('commercial.void_batches'),
            'checks' => $batch->control_totals['checks'] ?? [],
            'importWarnings' => $batch->control_totals['warnings'] ?? [],
        ]);
    }
}
