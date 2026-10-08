<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsIncident;
use App\Models\HsIncidentAction;
use App\Models\Permission;
use App\Services\HealthSafety\IncidentWorkflowService;
use App\Services\HealthSafety\RegisterQueries;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Corrective and preventive actions: those on incidents in the user's part of the register, and any assigned to them.
 * The person an action is assigned to may mark it done without any Health & Safety permission; verifying is the
 * officer's.
 */
class Actions extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithPagination;

    /** '' (all), 'open', 'overdue', 'done' (awaiting verification) or 'verified'. */
    #[Url(except: '')]
    public string $filter = '';

    #[Url(except: false)]
    public bool $mine = false;

    /** What an assignee writes when marking an action done, keyed by action id. @var array<int, string> */
    public array $notes = [];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.report_incident', 'health_safety.view_incidents');
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function complete(int $actionId, IncidentWorkflowService $workflow): void
    {
        $action = $this->actionForActor($actionId);

        $this->validate(['notes.'.$actionId => ['nullable', 'string', 'max:1000']]);

        $workflow->completeAction($action, $this->actor(), $this->notes[$actionId] ?? null);

        unset($this->notes[$actionId]);
        $this->dispatch('toast', type: 'success', message: 'Action marked done.');
    }

    public function verify(int $actionId, IncidentWorkflowService $workflow): void
    {
        $action = $this->actionForActor($actionId);

        $workflow->verifyAction($action, $this->actor());
        $this->dispatch('toast', type: 'success', message: 'Action verified.');
    }

    /** An action the actor can see at all; one outside their reach is a 403, never "not found". */
    protected function actionForActor(int $actionId): HsIncidentAction
    {
        $action = HsIncidentAction::query()->with('incident')->findOrFail($actionId);

        abort_unless($this->actionsForActor()->whereKey($action->id)->exists(), 403, 'This action is not available to you.');

        return $action;
    }

    protected function actionsForActor(): Builder
    {
        return app(RegisterQueries::class)->actionsVisibleTo($this->actor());
    }

    /** @return array<string, mixed> the filters as RegisterQueries (and the export route) take them */
    public function filters(): array
    {
        return array_filter(['filter' => $this->filter, 'mine' => $this->mine ? '1' : ''], fn ($value) => $value !== '');
    }

    public function render()
    {
        $employeeId = $this->actorEmployee()?->id;

        $actions = app(RegisterQueries::class)->actions($this->actor(), $this->filters())
            ->with(['incident', 'assignee'])
            ->orderByRaw("case status when 'open' then 0 when 'done' then 1 else 2 end")
            ->orderBy('due_on')
            ->paginate(15);

        return view('livewire.health_safety.actions', [
            'actions' => $actions,
            'employeeId' => $employeeId,
            'viewer' => $this->actor(),
            'visibility' => $this->visibility(),
            'canExport' => $this->actorCan('health_safety.export_reports') && $this->actorCan('health_safety.view_incidents'),
            'exportFilters' => $this->filters(),
        ]);
    }
}
