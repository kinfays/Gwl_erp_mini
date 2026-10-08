<?php

namespace App\Services\HealthSafety;

use App\Models\District;
use App\Models\HsSite;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Validation\ValidationException;

/**
 * Where a piece of equipment is: at a site OR in a vehicle, never both and never neither (the database has no portable
 * CHECK for it, so this is the rule, and a test pins it). The item's region and district are copied from the site; a
 * vehicle carries neither, so for a vehicle the form supplies them. Whoever places an item must be allowed to place it in
 * that region / district.
 */
class EquipmentLocation
{
    public function __construct(protected EquipmentScope $scope) {}

    /**
     * @param  array<string, mixed>  $data  site_id, vehicle_id, and for a vehicle region_id and district_id
     * @return array{site_id: int|null, vehicle_id: int|null, region_id: int, district_id: int|null}
     */
    public function resolve(User $actor, array $data): array
    {
        $siteId = filled($data['site_id'] ?? null) ? (int) $data['site_id'] : null;
        $vehicleId = filled($data['vehicle_id'] ?? null) ? (int) $data['vehicle_id'] : null;

        if (($siteId === null) === ($vehicleId === null)) {
            throw ValidationException::withMessages(['location' => 'Say where it is: at a site or in a vehicle, not both.']);
        }

        if ($siteId !== null) {
            $site = HsSite::query()->find($siteId);

            if (! $site || ! $site->is_active) {
                throw ValidationException::withMessages(['site_id' => 'Choose an active site.']);
            }

            $place = ['site_id' => $site->id, 'vehicle_id' => null, 'region_id' => (int) $site->region_id, 'district_id' => $site->district_id ? (int) $site->district_id : null];
        } else {
            $vehicle = Vehicle::query()->find($vehicleId);

            if (! $vehicle) {
                throw ValidationException::withMessages(['vehicle_id' => 'Choose a vehicle from the fleet.']);
            }

            $regionId = filled($data['region_id'] ?? null) ? (int) $data['region_id'] : $this->scope->regionIdOf($actor);

            if (! $regionId) {
                throw ValidationException::withMessages(['region_id' => 'Choose the region the vehicle is based in.']);
            }

            $districtId = filled($data['district_id'] ?? null) ? (int) $data['district_id'] : null;

            if ($districtId !== null && ! District::query()->whereKey($districtId)->where('region_id', $regionId)->exists()) {
                throw ValidationException::withMessages(['district_id' => 'That district is not in the chosen region.']);
            }

            $place = ['site_id' => null, 'vehicle_id' => $vehicle->id, 'region_id' => $regionId, 'district_id' => $districtId];
        }

        abort_unless($this->scope->canPlaceIn($actor, $place['region_id'], $place['district_id']), 403, 'You may not place equipment there.');

        return $place;
    }
}
