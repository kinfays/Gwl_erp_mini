<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;
use App\Services\Transport\TransportScope;

/**
 * Permissions say what a person may do; TransportScope says which vehicles they may do it to. Both must hold, and the
 * transport_manager role has no bypass here: it is bound by location like any other holder of the permissions.
 * Only super_admin passes every check. A vehicle outside the user's reach is answered with 404 by the callers
 * (TransportScope::authorizeVehicle / findVehicle), not with the 403 this policy would give.
 */
class VehiclePolicy
{
    public function __construct(protected TransportScope $scope) {}

    public function before(User $user): ?bool
    {
        return $user->hasRoles('super_admin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('transport.view_vehicles')
            || $user->hasPermission('transport.view_own_vehicle');
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $this->scope->contains($user, $vehicle)
            && (
                $user->hasPermission('transport.view_vehicles')
                || (
                    $user->hasPermission('transport.view_own_vehicle')
                    && in_array($user->id, [$vehicle->assigned_user_id, $vehicle->assigned_driver_id], true)
                )
            );
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('transport.create_vehicles')
            && $this->scope->locationOf($user) !== null;
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->hasPermission('transport.edit_vehicles')
            && $this->scope->contains($user, $vehicle);
    }

    public function assign(User $user, Vehicle $vehicle): bool
    {
        return $user->hasPermission('transport.assign_vehicles')
            && $this->scope->contains($user, $vehicle);
    }
}
