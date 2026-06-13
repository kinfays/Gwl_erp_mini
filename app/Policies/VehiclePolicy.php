<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRoles('super_admin', 'transport_manager')
            || $user->hasPermission('transport.view_vehicles')
            || $user->hasPermission('transport.view_own_vehicle');
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->hasRoles('super_admin', 'transport_manager')
            || $user->hasPermission('transport.view_vehicles')
            || (
                $user->hasPermission('transport.view_own_vehicle')
                && in_array($user->id, [$vehicle->assigned_user_id, $vehicle->assigned_driver_id], true)
            );
    }

    public function create(User $user): bool
    {
        return $user->hasRoles('super_admin', 'transport_manager')
            || $user->hasPermission('transport.create_vehicles');
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->hasRoles('super_admin', 'transport_manager')
            || $user->hasPermission('transport.edit_vehicles');
    }

    public function assign(User $user, Vehicle $vehicle): bool
    {
        return $user->hasRoles('super_admin', 'transport_manager')
            || $user->hasPermission('transport.assign_vehicles');
    }
}
