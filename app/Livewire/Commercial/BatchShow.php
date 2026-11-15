<?php

namespace App\Livewire\Commercial;

use App\Jobs\Commercial\ProcessBillingDetailsBatch;
use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingRouteStat;
use App\Models\CommercialReadingStat;
use App\Models\District;
use App\Models\Employee;
use App\Models\Permission;
use App\Services\Commercial\BatchLifecycleService;
use App\Services\Commercial\BatchResolutionService;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
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

        abort_unless(
            ($this->batch->isBilling() && $this->batch->routes()->whereNull('district_id')->where('district_label_raw', $label)->exists())
            || ($this->batch->isBillingDetails() && $this->batch->detailRollups()->whereNull('route_id')->where('district_label_raw', $label)->exists())
            || ($this->batch->isReadingRoute() && $this->batch->routeStats()->whereNull('route_id')->whereNotNull('route_label_raw')->where('district_label_raw', $label)->exists()),
            404
        );

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

        abort_unless(
            ($this->batch->isReading() && $this->batch->stats()->where('reader_staff_id', $staffId)->where('match_status', CommercialReadingStat::MATCH_UNMATCHED)->exists())
            || ($this->batch->isReadingRoute() && $this->batch->routeStats()->where('cca_staff_id', $staffId)->where('match_status', CommercialReadingRouteStat::MATCH_UNMATCHED)->exists()),
            404
        );

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
        $this->dispatch('toast', type: 'success', message: "CCA linked to {$employee->full_name} ({$rows} rows).");
    }

    // ---------------------------------------------------------------- Phase 8d: a Detailed Billing Report run

    /** The figures of a checked upload go live. */
    public function loadFigures(BillingDetailsImportService $imports): void
    {
        $this->authorizeBatchAction('commercial.upload_reports');
        abort_unless($this->batch->isBillingDetails(), 404);

        try {
            $imports->confirm($this->batch);
        } catch (CommercialImportException $exception) {
            $this->addError('run', $exception->getMessage());

            return;
        }

        $this->batch->refresh();
        $this->dispatch('toast', type: 'success', message: 'The figures are loaded and count in the analyses.');
    }

    /** An upload that never went live (waiting for its check, blocked or failed) is deleted with everything it wrote. */
    public function discardRun(BillingDetailsImportService $imports): void
    {
        $this->authorizeBatchAction('commercial.upload_reports');
        abort_unless($this->batch->isBillingDetails(), 404);

        try {
            $imports->discard($this->batch, auth()->id());
        } catch (CommercialImportException $exception) {
            $this->addError('run', $exception->getMessage());

            return;
        }

        $this->redirectRoute('commercial.batches', navigate: false);
    }

    /** A failed or blocked upload goes back in the queue (it starts again from its stored file, with the settings as they are now). */
    public function runAgain(BillingDetailsImportService $imports): void
    {
        $this->authorizeBatchAction('commercial.upload_reports');
        abort_unless($this->batch->isBillingDetails(), 404);

        try {
            $imports->requeue($this->batch);
        } catch (CommercialImportException $exception) {
            $this->addError('run', $exception->getMessage());

            return;
        }

        ProcessBillingDetailsBatch::dispatch($this->batch->id);
        $this->batch->refresh();
        $this->dispatch('toast', type: 'success', message: 'Queued again.');
    }

    public function startVoid(): void
    {
        $this->authorizeBatchAction('commercial.void_batches');

        abort_if($this->batch->isVoided() || ! $this->batch->isLive(), 404);

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

    /**
     * What the page of a reading-by-route batch shows (Phase 8c): the CCAs not in the staff directory, the route rows whose district was
     * not matched, the findings kept (CCAs with no routes or no visits, owner decisions, flagged rows) and the rows themselves. The batch
     * is already known to be in the actor's region (abortUnlessBatchAccessible); a district user never reaches a batch they did not upload.
     *
     * @return array<string, mixed>
     */
    protected function routeReadingData(CommercialImportBatch $batch): array
    {
        $unmatchedCcas = $batch->routeStats()->where('match_status', CommercialReadingRouteStat::MATCH_UNMATCHED)
            ->select('cca_staff_id', DB::raw('max(cca_name_raw) as cca_name'), DB::raw('count(*) as route_rows'), DB::raw('sum(visited_count) as visited_total'))
            ->groupBy('cca_staff_id')->orderBy('cca_staff_id')->get();
        $unmatchedDistricts = $batch->routeStats()->whereNull('route_id')->whereNotNull('route_label_raw')
            ->select('district_label_raw', DB::raw('count(*) as route_count'))->groupBy('district_label_raw')->orderBy('district_label_raw')->get();
        $perCca = $batch->routeStats()->select('cca_staff_id', DB::raw('sum(case when route_label_raw is null then 0 else 1 end) as route_rows'), DB::raw('sum(visited_count) as visited_total'))->groupBy('cca_staff_id')->get();
        $term = trim($this->routeSearch);

        return [
            'needsMatching' => $unmatchedCcas->count() + $unmatchedDistricts->count(),
            'unmatchedReaders' => $unmatchedCcas,
            'unmatchedDistricts' => $unmatchedDistricts,
            'districtOptions' => District::query()->where('region_id', $batch->region_id ?? 0)->orderBy('district_name')->get(),
            'candidates' => $this->linkingReaderId !== null && trim($this->employeeSearch) !== ''
                ? Employee::query()->visibleTo($this->actor())
                    ->where(fn ($query) => $query->where('staff_id', trim($this->employeeSearch))->orWhere('full_name', 'like', '%'.$term.'%'))
                    ->orderBy('full_name')->limit(8)->get()
                : collect(),
            'ccaNoRoutes' => $perCca->where('route_rows', 0)->pluck('cca_staff_id')->values(),
            'ccaNoVisits' => $perCca->filter(fn ($r) => (int) $r->route_rows > 0 && (int) $r->visited_total === 0)->pluck('cca_staff_id')->values(),
            'assignedNoVisits' => $batch->routeStats()->whereNotNull('route_label_raw')->where('is_route_owner', false)->count(),
            'flaggedRows' => $batch->routeStats()->where('is_flagged', true)->orderBy('cca_staff_id')->limit(50)->get(),
            'matchStatuses' => [CommercialReadingRouteStat::MATCH_MATCHED, CommercialReadingRouteStat::MATCH_UNMATCHED],
            'rows' => $batch->routeStats()
                ->with(['employee', 'district'])
                ->when($this->matchFilter !== '', fn ($query) => $query->where('match_status', $this->matchFilter))
                ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                    ->where('route_label_raw', 'like', '%'.$term.'%')
                    ->orWhere('cca_staff_id', $term)
                    ->orWhere('cca_name_raw', 'like', '%'.$term.'%')))
                ->orderBy('cca_staff_id')->orderBy('route_label_raw')
                ->paginate(20),
        ];
    }

    /**
     * What the page of a Detailed Billing Report upload shows (Phase 8d): where the run is, the figures it found (districts, bill statuses, the
     * checks), the routes whose district was not matched, the link to the customer list. Counts and route headings only: never a name, address,
     * phone or meter number, and account numbers only on the Billing details lists.
     *
     * @return array<string, mixed>
     */
    protected function billingDetailsData(CommercialImportBatch $batch): array
    {
        $control = $batch->control_totals ?? [];
        $names = District::query()->where('region_id', $batch->region_id ?? 0)->pluck('district_name', 'id');
        $perDistrict = [];

        foreach ($batch->detailRollups()->toBase()->select('district_id', 'district_label_raw', DB::raw('count(distinct route_label_raw) as routes'), DB::raw('sum(customers) as customers'), DB::raw('sum(month_total) as month_total'), DB::raw('sum(paid_this_month) as paid'), DB::raw('sum(balance_due) as balance_due'))->groupBy('district_id', 'district_label_raw')->get() as $r) {
            $key = $r->district_id !== null ? 'd'.$r->district_id : 'l'.($r->district_label_raw ?? '');
            $row = &$perDistrict[$key];
            $row ??= ['name' => $r->district_id !== null ? ($names[$r->district_id] ?? 'District '.$r->district_id) : ($r->district_label_raw !== null ? $r->district_label_raw.' (not matched)' : 'No district in the label'), 'routes' => 0, 'customers' => 0, 'month_total' => 0.0, 'paid' => 0.0, 'balance_due' => 0.0];
            $row['routes'] += (int) $r->routes;
            $row['customers'] += (int) $r->customers;
            $row['month_total'] += (float) $r->month_total;
            $row['paid'] += (float) $r->paid;
            $row['balance_due'] += (float) $r->balance_due;
            unset($row);
        }

        $unmatched = $batch->detailRollups()->whereNull('route_id')->where('route_label_raw', '!=', BillingDetailsImportService::NO_ROUTE_HEADING)->whereNotNull('district_label_raw')
            ->select('district_label_raw', DB::raw('count(distinct route_label_raw) as route_count'))->groupBy('district_label_raw')->orderBy('district_label_raw')->get();

        return [
            'control' => $control,
            'inFlight' => $batch->isInFlight(),
            'perDistrict' => collect($perDistrict)->sortBy('name')->values(),
            'unmatchedDistricts' => $unmatched,
            'districtOptions' => District::query()->where('region_id', $batch->region_id ?? 0)->orderBy('district_name')->get(),
            'needsMatching' => $unmatched->count(),
            'canRun' => $this->actorCan('commercial.upload_reports'),
            'describeDiffs' => fn (array $diffs) => app(BillingDetailsImportService::class)->describeDiffs($diffs),
            'blockedErrors' => $control['errors'] ?? [],
        ];
    }

    /** Every action re-checks the permission AND that the batch is in the actor's region (Livewire calls are replayable). */
    protected function authorizeBatchAction(string $permission): void
    {
        $this->guardCommercialPermission($permission);
        $this->abortUnlessBatchAccessible($this->batch);
    }

    public function render()
    {
        // a Detailed Billing Report run moves on in the background: show where it is now
        if ($this->batch->isBillingDetails()) {
            $this->batch->refresh();
        }

        $batch = $this->batch;
        $data = ['batchTypeLabel' => CommercialImportBatch::typeLabel($batch->report_type)];

        if ($batch->isBillingDetails()) {
            $data += $this->billingDetailsData($batch);
        } elseif ($batch->isReading()) {
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
        } elseif ($batch->isReadingRoute()) {
            $data += $this->routeReadingData($batch);
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
            'needsMatching' => $batch->isReadingRoute() || $batch->isBillingDetails() ? ($data['needsMatching'] ?? 0) : max(0, $batch->row_count - $batch->matched_count),
            'canResolve' => $this->actorCan('commercial.resolve_matches'),
            'canVoid' => $this->actorCan('commercial.void_batches') && $batch->isLive(),
            'checks' => $batch->control_totals['checks'] ?? [],
            'importWarnings' => $batch->control_totals['warnings'] ?? [],
        ]);
    }
}
