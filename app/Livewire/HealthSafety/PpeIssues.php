<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\Employee;
use App\Models\HsPpeIssue;
use App\Models\HsPpeType;
use App\Models\Permission;
use App\Services\HealthSafety\PpeIssueService;
use App\Services\HealthSafety\RegisterQueries;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * PPE held by staff: the list (with each open issue's computed state), issuing PPE to someone, and closing an issue.
 *
 * Issuing offers the replacement step: the person's open rows of the chosen type are listed, and those that are due or
 * overdue default to "worn out" so they are closed in the same transaction as the new issue. "Already held" records what
 * someone has had for a while without taking anything from a store.
 */
class PpeIssues extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithPagination;

    /** 'open' (default), 'all' or a closed status. */
    #[Url(except: 'open')]
    public string $status = 'open';

    /** overdue, replacement_due or ok (open issues). The overview links here with ?state=overdue. */
    #[Url(except: '')]
    public string $state = '';

    #[Url(except: '')]
    public string $typeId = '';

    #[Url(except: '')]
    public string $search = '';

    /** Open the issue form on arrival (the stock screen links here). */
    #[Url(as: 'issue', except: false)]
    public bool $openIssue = false;

    /** 'issue', 'close' or '' (which form is open). */
    #[Locked]
    public string $panel = '';

    // Issue form
    public ?int $issueEmployeeId = null;

    public string $issueEmployeeLabel = '';

    public string $employeeSearch = '';

    public ?int $issueTypeId = null;

    public string $issueSize = '';

    public string $issueQuantity = '1';

    public ?int $issueStoreId = null;

    public bool $issueHistoric = false;

    public string $issueIssuedOn = '';

    public string $issueExpiresOn = '';

    /** The person's open rows of this type that this issue replaces, keyed by issue id. @var array<int, array{outcome: string, store_id: int|string|null, note: string}> */
    public array $closings = [];

    // Close form
    #[Locked]
    public ?int $closeIssueId = null;

    public string $closeOutcome = '';

    public string $closeOn = '';

    public string $closeNote = '';

    public ?int $closeStoreId = null;

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.view_equipment');

        if ($this->openIssue && $this->actorCan('health_safety.manage_ppe')) {
            $this->startIssue();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'state', 'typeId', 'search'], true)) {
            $this->resetPage();
        }
    }

    // ------------------------------------------------------------------ issuing

    public function startIssue(): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_ppe');

        $this->resetPanels();
        $this->panel = 'issue';
        $this->issueIssuedOn = today()->toDateString();
        $this->issueStoreId = $this->equipmentScope()->ppeStores($this->actor())->orderBy('name')->value('id');
    }

    public function chooseEmployee(int $employeeId): void
    {
        $employee = $this->ppeEmployeeMatches($this->employeeSearch, 50)->firstWhere('id', $employeeId);

        if ($employee) {
            $this->issueEmployeeId = $employee->id;
            $this->issueEmployeeLabel = $employee->full_name.' ('.$employee->staff_id.')';
            $this->employeeSearch = '';
            $this->loadClosings();
        }
    }

    public function clearEmployee(): void
    {
        $this->issueEmployeeId = null;
        $this->issueEmployeeLabel = '';
        $this->closings = [];
    }

    public function updatedIssueTypeId(): void
    {
        $this->issueSize = '';
        $this->issueExpiresOn = '';
        $this->loadClosings();
    }

    public function updatedIssueStoreId(): void
    {
        foreach ($this->closings as $id => $closing) {
            $this->closings[$id]['store_id'] = $this->issueStoreId;
        }
    }

    public function saveIssue(PpeIssueService $issues): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_ppe');
        $this->resetErrorBag();

        $this->validate([
            'issueEmployeeId' => ['required', 'integer'],
            'issueTypeId' => ['required', 'integer'],
            'issueQuantity' => ['required', 'integer', 'min:1'],
            'issueIssuedOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'issueExpiresOn' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'issueEmployeeId.required' => 'Choose the member of staff.',
            'issueTypeId.required' => 'Choose the PPE type.',
            'issueQuantity.min' => 'Enter at least 1.',
            'issueIssuedOn.required' => 'Enter the date it was given out.',
        ]);

        $closings = collect($this->closings)
            ->filter(fn (array $closing) => ($closing['outcome'] ?? '') !== '')
            ->map(fn (array $closing, int|string $id) => [
                'issue_id' => $id,
                'outcome' => $closing['outcome'],
                'note' => $closing['note'] ?? null,
                'store_id' => filled($closing['store_id'] ?? null) ? $closing['store_id'] : null,
            ])
            ->values()
            ->all();

        $issues->issue($this->actor(), [
            'employee_id' => $this->issueEmployeeId,
            'ppe_type_id' => $this->issueTypeId,
            'size' => $this->issueSize,
            'quantity' => $this->issueQuantity,
            'store_id' => $this->issueHistoric ? null : $this->issueStoreId,
            'is_historic' => $this->issueHistoric,
            'issued_on' => $this->issueIssuedOn,
            'expires_on' => $this->issueExpiresOn ?: null,
        ], $closings);

        $this->resetPanels();
        $this->dispatch('toast', type: 'success', message: 'PPE issued.');
    }

    // ------------------------------------------------------------------ closing

    public function startClose(int $issueId): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_ppe');
        $issue = $this->issueOf($issueId);

        $this->resetPanels();
        $this->panel = 'close';
        $this->closeIssueId = $issue->id;
        $this->closeOn = today()->toDateString();
        $this->closeStoreId = $this->equipmentScope()->ppeStores($this->actor())->orderBy('name')->value('id');
    }

    public function saveClose(PpeIssueService $issues): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_ppe');
        $this->resetErrorBag();

        $this->validate([
            'closeOutcome' => ['required', 'in:'.implode(',', array_keys(HsPpeIssue::OUTCOMES))],
            'closeOn' => ['required', 'date_format:Y-m-d'],
            'closeNote' => ['nullable', 'string', 'max:1000'],
        ], ['closeOutcome.required' => 'Choose how it ended.']);

        $issue = $this->issueOf($this->closeIssueId);
        $store = $this->closeOutcome === HsPpeIssue::STATUS_RETURNED && ! $issue->is_historic
            ? \App\Models\HsSite::query()->find($this->closeStoreId)
            : null;

        $issues->close($this->actor(), $issue, $this->closeOutcome, $this->closeOn, $this->closeNote, $store);

        $this->resetPanels();
        $this->dispatch('toast', type: 'success', message: 'Issue closed.');
    }

    public function cancel(): void
    {
        $this->resetPanels();
    }

    // ------------------------------------------------------------------ internals

    /** An issue the actor may see; one for staff outside their reach is a 403, never "not found". */
    protected function issueOf(?int $id): HsPpeIssue
    {
        $issue = $this->equipmentScope()->ppeIssues($this->actor())->with(['employee', 'type'])->find($id);

        abort_unless($issue, 403, 'That issue is not available to you.');

        return $issue;
    }

    /** The person's open rows of the chosen type, with a sensible default for what happens to each. */
    protected function loadClosings(): void
    {
        $this->closings = [];

        if (! $this->issueEmployeeId || ! $this->issueTypeId) {
            return;
        }

        $employee = $this->equipmentScope()->employees($this->actor())->find($this->issueEmployeeId);

        if (! $employee) {
            return;
        }

        HsPpeIssue::query()->open()->where('employee_id', $employee->id)->where('ppe_type_id', $this->issueTypeId)->orderBy('issued_on')->get()
            ->each(function (HsPpeIssue $held) {
                // Rows that are due or overdue default to "worn out"; the rest are left open unless the officer says otherwise.
                $this->closings[$held->id] = [
                    'outcome' => in_array($held->state(), [HsPpeIssue::STATE_OVERDUE, HsPpeIssue::STATE_REPLACEMENT_DUE], true) ? HsPpeIssue::STATUS_WORN_OUT : '',
                    'store_id' => $this->issueStoreId,
                    'note' => '',
                ];
            });
    }

    protected function resetPanels(): void
    {
        $this->panel = '';
        $this->openIssue = false;
        $this->issueEmployeeId = null;
        $this->issueEmployeeLabel = '';
        $this->employeeSearch = '';
        $this->issueTypeId = null;
        $this->issueSize = '';
        $this->issueQuantity = '1';
        $this->issueStoreId = null;
        $this->issueHistoric = false;
        $this->issueIssuedOn = '';
        $this->issueExpiresOn = '';
        $this->closings = [];
        $this->closeIssueId = null;
        $this->closeOutcome = '';
        $this->closeOn = '';
        $this->closeNote = '';
        $this->closeStoreId = null;
        $this->resetErrorBag();
    }

    /** @return array<string, string> the filters as RegisterQueries (and the export route) take them */
    public function filters(): array
    {
        return array_filter([
            'status' => $this->status,
            'state' => $this->state,
            'type_id' => $this->typeId,
            'search' => $this->search,
        ], fn ($value) => $value !== '');
    }

    public function render()
    {
        $actor = $this->actor();
        $scope = $this->equipmentScope();

        $issues = app(RegisterQueries::class)->ppeIssues($actor, $this->filters())
            ->with(['employee:id,staff_id,full_name,job_title_id', 'employee.jobTitle:id,job_title_name', 'type:id,name', 'issuer:id,full_name'])
            ->orderByRaw('replace_due_on is null')
            ->orderBy('replace_due_on')
            ->orderByDesc('id')
            ->paginate(15);

        $issueType = $this->issueTypeId ? HsPpeType::query()->find($this->issueTypeId) : null;
        $held = $this->issueEmployeeId && $this->issueTypeId
            ? HsPpeIssue::query()->open()->where('employee_id', $this->issueEmployeeId)->where('ppe_type_id', $this->issueTypeId)->orderBy('issued_on')->get()->keyBy('id')
            : collect();

        $closing = $this->closeIssueId ? $this->issueOf($this->closeIssueId) : null;

        return view('livewire.health_safety.ppe-issues', [
            'issues' => $issues,
            'types' => HsPpeType::query()->orderBy('name')->get(['id', 'name', 'is_active']),
            'stores' => $scope->ppeStores($actor)->orderBy('name')->get(['id', 'name']),
            'issueType' => $issueType,
            'held' => $held,
            'employeeMatches' => $this->panel === 'issue' && ! $this->issueEmployeeId ? $this->ppeEmployeeMatches($this->employeeSearch) : collect(),
            'closingIssue' => $closing,
            'canManage' => $this->actorCan('health_safety.manage_ppe'),
            'canExport' => $this->actorCan('health_safety.export_reports'),
            'exportFilters' => $this->filters(),
            'statuses' => HsPpeIssue::STATUSES,
            'outcomes' => HsPpeIssue::OUTCOMES,
        ]);
    }
}
