<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\District;
use App\Models\HsIncident;
use App\Models\Permission;
use App\Services\HealthSafety\RegisterQueries;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The incident register, scoped by IncidentVisibility. Filters live in the URL so the overview tiles can link into it.
 * Reporters of confidential reports show as "Confidential" to anyone not entitled to see them.
 */
class IncidentIndex extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithPagination;

    /** A status, or 'open' / 'in_progress' for the groups the overview links to. */
    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $severity = '';

    #[Url(except: '')]
    public string $districtId = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $search = '';

    /** 'ack' (not acknowledged in time) or 'investigation' (past its due date): where the dashboard's overdue numbers link. */
    #[Url(except: '')]
    public string $overdue = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.view_incidents');

        if (! in_array($this->overdue, ['', 'ack', 'investigation'], true)) {
            $this->overdue = '';
        }
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['status', 'type', 'severity', 'districtId', 'from', 'to', 'search', 'overdue']);
        $this->resetPage();
    }

    /** @return array<string, string> the filters as RegisterQueries (and the export route) take them */
    public function filters(): array
    {
        return array_filter([
            'status' => $this->status,
            'type' => $this->type,
            'severity' => $this->severity,
            'district_id' => $this->districtId,
            'from' => $this->from,
            'to' => $this->to,
            'search' => $this->search,
            'overdue' => $this->overdue,
        ], fn ($value) => $value !== '');
    }

    /** The register after the filters, and before ordering: the very query the export reads. */
    protected function filtered(): Builder
    {
        return app(RegisterQueries::class)->incidents($this->actor(), $this->filters());
    }

    public function render()
    {
        $incidents = $this->filtered()
            ->with(['region', 'district', 'department', 'site', 'reporter', 'reporterEmployee', 'recorder'])
            ->latest('hs_incidents.occurred_on')
            ->latest('hs_incidents.id')
            ->paginate(15);

        return view('livewire.health_safety.incident-index', [
            'incidents' => $incidents,
            'visibility' => $this->visibility(),
            'viewer' => $this->actor(),
            'districts' => District::query()
                ->when(! $this->actorSeesAllRegions(), fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
                ->orderBy('district_name')
                ->get(['id', 'district_name']),
            'statuses' => HsIncident::STATUSES,
            'types' => HsIncident::TYPES,
            'severities' => HsIncident::SEVERITIES,
            'canExport' => $this->actorCan('health_safety.export_reports'),
            'exportFilters' => $this->filters(),
        ]);
    }
}
