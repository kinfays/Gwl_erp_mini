<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\District;
use App\Models\HsSite;
use App\Models\Permission;
use App\Models\Region;
use App\Services\HealthSafety\ExpiryRegisterService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every dated item in the user's part of the register on one screen, most urgent first: extinguisher expiry, service and
 * hydrostatic test dates, first aid kit item expiry and PPE replacement dates. Built only by ExpiryRegisterService, the
 * same source the counts, the exports and the alert command use. Every filter lives in the URL so the dashboard can link
 * straight to the rows behind a number.
 */
class ExpiryRegister extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithPagination;

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $bucket = '';

    /** Days ahead (30, 60, 90, 180) or "overdue". */
    #[Url(except: '90')]
    public string $horizon = '90';

    #[Url(except: '')]
    public string $regionId = '';

    #[Url(except: '')]
    public string $districtId = '';

    #[Url(except: '')]
    public string $siteId = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.view_equipment');

        // A value typed into the URL that means nothing is dropped, not an error page.
        if ($this->type !== '' && ! array_key_exists($this->type, ExpiryRegisterService::TYPES)) {
            $this->type = '';
        }

        if ($this->bucket !== '' && ! array_key_exists($this->bucket, ExpiryRegisterService::BUCKETS)) {
            $this->bucket = '';
        }

        if ($this->horizon !== 'overdue' && ! in_array((int) $this->horizon, ExpiryRegisterService::HORIZONS, true)) {
            $this->horizon = (string) ExpiryRegisterService::DEFAULT_HORIZON;
        }
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['type', 'bucket', 'regionId', 'districtId', 'siteId']);
        $this->horizon = (string) ExpiryRegisterService::DEFAULT_HORIZON;
        $this->resetPage();
    }

    /** @return array<string, string> the filters as the service and the export routes take them */
    public function filters(): array
    {
        return array_filter([
            'type' => $this->type,
            'bucket' => $this->bucket,
            'horizon' => $this->horizon,
            'region_id' => $this->regionId,
            'district_id' => $this->districtId,
            'site_id' => $this->siteId,
        ], fn ($value) => $value !== '');
    }

    public function render(ExpiryRegisterService $register)
    {
        $user = $this->actor();
        $scope = $this->equipmentScope();
        $seesAll = $scope->seesAllRegions($user);
        $filters = $this->filters();

        $rows = $register->paginate($user, $filters, 20, $this->getPage(), request()->url());

        return view('livewire.health_safety.expiry-register', [
            'rows' => $rows,
            'counts' => $register->counts($user, $filters),
            'types' => ExpiryRegisterService::TYPES,
            'buckets' => ExpiryRegisterService::BUCKETS,
            'horizons' => ExpiryRegisterService::HORIZONS,
            'seesAll' => $seesAll,
            'regions' => $seesAll ? Region::query()->orderBy('region_name')->get(['id', 'region_name']) : collect(),
            'districts' => District::query()
                ->when(! $seesAll, fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
                ->when($seesAll && $this->regionId !== '', fn (Builder $query) => $query->where('region_id', (int) $this->regionId))
                ->orderBy('district_name')
                ->get(['id', 'district_name']),
            'sites' => HsSite::query()
                ->when(! $seesAll, fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
                ->orderBy('name')
                ->get(['id', 'name']),
            'canExport' => $this->actorCan('health_safety.export_reports'),
            'exportFilters' => $filters,
        ]);
    }
}
