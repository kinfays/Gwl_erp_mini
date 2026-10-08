<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\PrintsEquipmentLabels;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\District;
use App\Models\HsFirstAidItemTemplate;
use App\Models\HsFirstAidKit;
use App\Models\HsSite;
use App\Models\Permission;
use App\Services\HealthSafety\EquipmentExpiryService;
use App\Services\HealthSafety\HealthSafetySettings;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The first aid kit register, with each kit's computed state. Filters live in the URL (?state=item_expired,
 * ?expiring=30) so the overview counts link straight to the rows behind them; built by EquipmentExpiryService.
 */
class FirstAidKits extends Component
{
    use EnforcesModuleAccess;
    use PrintsEquipmentLabels;
    use ScopesHealthSafetyByActor;
    use WithPagination;

    /** missing, item_expired, item_expiring, incomplete, check_failed, check_overdue or ok */
    #[Url(except: '')]
    public string $state = '';

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

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.view_equipment');

        $this->normaliseLabelFilter();

        if ($this->state !== '' && ! in_array($this->state, HsFirstAidKit::stateNames(), true)) {
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
        ];
    }

    protected function labelType(): string
    {
        return 'kit';
    }

    protected function labelQuery(EquipmentExpiryService $equipment): Builder
    {
        return $equipment->kits($this->actor(), $this->filters());
    }

    public function render(EquipmentExpiryService $equipment)
    {
        $user = $this->actor();
        $scope = $this->equipmentScope();

        $kits = $equipment->kits($user, $this->filters())->with(['site', 'vehicle', 'district', 'items'])->paginate(15);

        return view('livewire.health_safety.first-aid-kits', $this->labelViewData() + [
            'kits' => $kits,
            'canManage' => $this->actorCan('health_safety.manage_equipment'),
            'states' => HsFirstAidKit::states(),
            'types' => HsFirstAidKit::TYPES,
            'statuses' => HsFirstAidKit::STATUSES,
            'noTemplates' => $this->actorCan('health_safety.manage_equipment') && ! HsFirstAidItemTemplate::query()->exists(),
            'districts' => District::query()
                ->when(! $scope->seesAllRegions($user), fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
                ->orderBy('district_name')
                ->get(['id', 'district_name']),
            'sites' => HsSite::query()
                ->when(! $scope->seesAllRegions($user), fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
                ->orderBy('name')
                ->get(['id', 'name']),
            'canExport' => $this->actorCan('health_safety.export_reports'),
            'exportFilters' => array_filter($this->filters(), fn ($value) => $value !== '' && $value !== false),
            'warningDays' => (int) HealthSafetySettings::value('hs_expiry_warning_days'),
        ]);
    }
}
