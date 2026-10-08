<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\District;
use App\Models\HsPpeEntitlement;
use App\Models\HsPpeType;
use App\Models\JobTitle;
use App\Models\Permission;
use App\Services\HealthSafety\PpeComplianceService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Who is missing PPE they are entitled to: every active member of staff in the viewer's part of the register whose job title
 * has entitlements, against what they hold. The rows come from PpeComplianceService, the same evaluator My PPE and the
 * overview use. Filters live in the URL so the overview can link here (?state=gap, ?state=overdue).
 */
class PpeGaps extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithPagination;

    /** missing, overdue, short, replacement_due, ok, or "gap" (missing, overdue or short). */
    #[Url(except: '')]
    public string $state = '';

    #[Url(except: '')]
    public string $districtId = '';

    #[Url(except: '')]
    public string $jobTitleId = '';

    #[Url(except: '')]
    public string $typeId = '';

    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.view_equipment');

        // A state typed into the URL that means nothing is dropped rather than shown as an empty list.
        if ($this->state !== '' && $this->state !== 'gap' && ! array_key_exists($this->state, PpeComplianceService::STATES)) {
            $this->state = '';
        }
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['state', 'districtId', 'jobTitleId', 'typeId', 'search']);
        $this->resetPage();
    }

    /** @return array<string, string> the filters as PpeComplianceService (and the export route) take them */
    public function filters(): array
    {
        return array_filter([
            'state' => $this->state,
            'district_id' => $this->districtId,
            'job_title_id' => $this->jobTitleId,
            'type_id' => $this->typeId,
            'search' => $this->search,
        ], fn ($value) => $value !== '');
    }

    public function render(PpeComplianceService $compliance)
    {
        $actor = $this->actor();
        $scope = $this->equipmentScope();

        $rows = $compliance->rows($actor, $this->filters());

        return view('livewire.health_safety.ppe-gaps', [
            'page' => $compliance->paginate($rows, 20, $this->getPage(), request()->url()),
            'summary' => $compliance->summary($actor),
            'canExport' => $this->actorCan('health_safety.export_reports'),
            'exportFilters' => $this->filters(),
            'canSetUp' => $this->actorCan('health_safety.manage_master_data'),
            'states' => PpeComplianceService::STATES,
            'districts' => District::query()
                ->when(! $scope->seesAllRegions($actor), fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
                ->orderBy('district_name')
                ->get(['id', 'district_name']),
            'jobTitles' => JobTitle::query()->whereIn('id', HsPpeEntitlement::query()->select('job_title_id'))->orderBy('job_title_name')->get(['id', 'job_title_name']),
            'types' => HsPpeType::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
