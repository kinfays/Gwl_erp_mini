<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\PrintsEquipmentLabels;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\District;
use App\Models\HsFireExtinguisher;
use App\Models\HsSite;
use App\Models\Permission;
use App\Services\HealthSafety\EquipmentExpiryService;
use App\Services\HealthSafety\HealthSafetySettings;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The extinguisher register: every unit in the user's part of the register, with its computed state. Filters live in the
 * URL (?state=expired, ?expiring=30) so the overview counts link straight to the rows behind them; the list is built by
 * EquipmentExpiryService, the same query the overview counts.
 */
class Extinguishers extends Component
{
    use EnforcesModuleAccess;
    use PrintsEquipmentLabels;
    use ScopesHealthSafetyByActor;
    use WithPagination;

    /** A computed state (expired, service_overdue, hydro_overdue, expiring, service_due_soon, check_failed, check_overdue, ok). */
    #[Url(except: '')]
    public string $state = '';

    /** Only units whose expiry date falls within this many days. */
    #[Url(except: '')]
    public string $expiring = '';

    #[Url(except: '')]
    public string $districtId = '';

    #[Url(except: '')]
    public string $siteId = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: false)]
    public bool $inVehicles = false;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'expiry')]
    public string $sort = 'expiry';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.view_equipment');

        $this->normaliseLabelFilter();

        // A state or window typed into the URL that means nothing is dropped, not an error page.
        if ($this->state !== '' && ! in_array($this->state, HsFireExtinguisher::stateNames(), true)) {
            $this->state = '';
        }

        if ($this->expiring !== '' && (! ctype_digit($this->expiring) || (int) $this->expiring < 1 || (int) $this->expiring > 3650)) {
            $this->expiring = '';
        }
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function sortByExpiry(): void
    {
        $this->sort = $this->sort === 'expiry' ? '-expiry' : 'expiry';
    }

    public function resetFilters(): void
    {
        $this->reset(['state', 'expiring', 'districtId', 'siteId', 'type', 'status', 'inVehicles', 'search', 'label', 'selected']);
        $this->resetPage();
    }

    /** @return array<string, mixed> */
    protected function filters(): array
    {
        return [
            'state' => $this->state,
            'expiring' => $this->expiring,
            'district_id' => $this->districtId,
            'site_id' => $this->siteId,
            'type' => $this->type,
            'status' => $this->status,
            'in_vehicles' => $this->inVehicles,
            'search' => $this->search,
            'label' => $this->label,
            'sort' => $this->sort,
        ];
    }

    protected function labelType(): string
    {
        return 'extinguisher';
    }

    protected function labelQuery(EquipmentExpiryService $equipment): Builder
    {
        return $equipment->extinguishers($this->actor(), $this->filters());
    }

    public function render(EquipmentExpiryService $equipment)
    {
        $user = $this->actor();
        $scope = $this->equipmentScope();

        $units = $equipment->extinguishers($user, $this->filters())
            ->with(['site', 'vehicle', 'district'])
            ->paginate(15);

        return view('livewire.health_safety.extinguishers', $this->labelViewData() + [
            'units' => $units,
            'scope' => $scope,
            'viewer' => $user,
            'canManage' => $this->actorCan('health_safety.manage_equipment'),
            'states' => HsFireExtinguisher::states(),
            'types' => HsFireExtinguisher::TYPES,
            'statuses' => HsFireExtinguisher::STATUSES,
            'districts' => District::query()
                ->when(! $scope->seesAllRegions($user), fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
                ->orderBy('district_name')
                ->get(['id', 'district_name']),
            'sites' => HsSite::query()
                ->when(! $scope->seesAllRegions($user), fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
                ->orderBy('name')
                ->get(['id', 'name']),
            'canExport' => $this->actorCan('health_safety.export_reports'),
            'exportFilters' => array_filter(array_diff_key($this->filters(), ['sort' => 1]), fn ($value) => $value !== '' && $value !== false),
            'warningDays' => (int) HealthSafetySettings::value('hs_expiry_warning_days'),
        ]);
    }
}
