<?php

namespace App\Livewire\HealthSafety\Concerns;

use App\Models\District;
use App\Models\HsSite;
use App\Models\Region;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The "where is it" and "who is responsible" part shared by the extinguisher and first aid kit forms: at a site or in a
 * vehicle (exactly one), the district and place within it, and the responsible person. The rules themselves are the
 * services' (EquipmentLocation); this only collects the input. Needs ScopesHealthSafetyByActor on the same component.
 */
trait PicksEquipmentLocation
{
    /** 'site' or 'vehicle' */
    public string $locationMode = 'site';

    public ?int $siteId = null;

    public ?int $vehicleId = null;

    public string $vehicleSearch = '';

    public string $vehicleLabel = '';

    /** Vehicles carry no region or district, so for one the form asks. */
    public ?int $regionId = null;

    public ?int $districtId = null;

    public string $locationDetail = '';

    public ?int $responsibleEmployeeId = null;

    public string $responsibleSearch = '';

    public string $responsibleLabel = '';

    public function updatedLocationMode(): void
    {
        $this->resetErrorBag(['location', 'site_id', 'vehicle_id', 'region_id', 'district_id']);
    }

    public function updatedRegionId(): void
    {
        $this->districtId = null;
    }

    public function chooseVehicle(int $vehicleId): void
    {
        $vehicle = $this->vehicleMatches($this->vehicleSearch, 50)->firstWhere('id', $vehicleId);

        if ($vehicle) {
            $this->vehicleId = $vehicle->id;
            $this->vehicleLabel = $vehicle->number_plate.' ('.trim($vehicle->brand.' '.$vehicle->model).')';
            $this->vehicleSearch = '';
        }
    }

    public function clearVehicle(): void
    {
        $this->vehicleId = null;
        $this->vehicleLabel = '';
    }

    public function chooseResponsible(int $employeeId): void
    {
        $employee = $this->employeeMatches($this->responsibleSearch, 50)->firstWhere('id', $employeeId);

        if ($employee) {
            $this->responsibleEmployeeId = $employee->id;
            $this->responsibleLabel = $employee->full_name.' ('.$employee->staff_id.')';
            $this->responsibleSearch = '';
        }
    }

    public function clearResponsible(): void
    {
        $this->responsibleEmployeeId = null;
        $this->responsibleLabel = '';
    }

    /** The location fields in the shape the services take. @return array<string, mixed> */
    protected function locationData(): array
    {
        return [
            'site_id' => $this->locationMode === 'site' ? $this->siteId : null,
            'vehicle_id' => $this->locationMode === 'vehicle' ? $this->vehicleId : null,
            'region_id' => $this->locationMode === 'vehicle' ? ($this->regionId ?? $this->actorRegionId()) : null,
            'district_id' => $this->locationMode === 'vehicle' ? $this->districtId : null,
            'location_detail' => $this->locationDetail,
            'responsible_employee_id' => $this->responsibleEmployeeId,
        ];
    }

    /** Fill the location fields from an existing item. */
    protected function fillLocation(object $item): void
    {
        $this->locationMode = $item->vehicle_id ? 'vehicle' : 'site';
        $this->siteId = $item->site_id;
        $this->vehicleId = $item->vehicle_id;
        $this->vehicleLabel = $item->vehicle ? $item->vehicle->number_plate.' ('.trim($item->vehicle->brand.' '.$item->vehicle->model).')' : '';
        $this->regionId = $item->region_id;
        $this->districtId = $item->district_id;
        $this->locationDetail = (string) $item->location_detail;
        $this->responsibleEmployeeId = $item->responsible_employee_id;
        $this->responsibleLabel = $item->responsible ? $item->responsible->full_name.' ('.$item->responsible->staff_id.')' : '';
    }

    /** @return Collection<int, Vehicle> fleet vehicles (not retired) matching a plate, at most $limit */
    protected function vehicleMatches(string $term, int $limit = 8): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Vehicle::query()
            ->where('status', '!=', Vehicle::STATUS_RETIRED)
            ->where('number_plate', 'like', '%'.$term.'%')
            ->orderBy('number_plate')
            ->limit($limit)
            ->get(['id', 'number_plate', 'brand', 'model']);
    }

    /** Sites, regions and districts offered by the location pickers, limited to the actor's reach. @return array<string, mixed> */
    protected function locationOptions(): array
    {
        $seesAll = $this->equipmentScope()->seesAllRegions($this->actor());
        $regionId = $this->locationMode === 'vehicle' ? ($this->regionId ?? $this->actorRegionId()) : $this->actorRegionId();

        return [
            'locationSites' => HsSite::query()
                ->active()
                ->when(! $seesAll, fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
                ->with('district')
                ->orderBy('name')
                ->get(),
            'locationRegions' => $seesAll ? Region::query()->orderBy('region_name')->get(['id', 'region_name']) : collect(),
            'locationDistricts' => District::query()->where('region_id', $regionId ?? 0)->orderBy('district_name')->get(['id', 'district_name']),
            'vehicleMatches' => $this->locationMode === 'vehicle' && ! $this->vehicleId ? $this->vehicleMatches($this->vehicleSearch) : collect(),
            'responsibleMatches' => ! $this->responsibleEmployeeId && $this->responsibleSearch !== '' ? $this->employeeMatches($this->responsibleSearch) : collect(),
        ];
    }
}
