<?php

namespace App\Repositories\Transport;

use App\Models\User;
use App\Models\Vehicle;
use App\Services\Transport\TransportScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every read takes the acting user and goes through TransportScope, so there is no way to ask for "all vehicles".
 */
class VehicleRepository
{
    public function __construct(protected TransportScope $scope) {}

    public function query(User $user): Builder
    {
        return $this->scope->vehicles($user);
    }

    /** 404 for a vehicle that does not exist or is outside the user's reach. */
    public function findByUuid(string $uuid, User $user): Vehicle
    {
        return $this->query($user)->where('vehicles.uuid', $uuid)->firstOrFail();
    }

    /** The user's own (non-retired) vehicle, provided it is still inside their reach. */
    public function assignedTo(User $user): ?Vehicle
    {
        return $this->query($user)
            ->where('vehicles.status', '!=', Vehicle::STATUS_RETIRED)
            ->where(function (Builder $query) use ($user): void {
                $query->where('vehicles.assigned_user_id', $user->id)
                    ->orWhere('vehicles.assigned_driver_id', $user->id);
            })
            ->with(['assignedUser', 'assignedDriver', 'department'])
            ->first();
    }

    /**
     * Whether the employee already holds a vehicle. Deliberately NOT scoped: one person holds one vehicle, wherever it
     * is based, and the answer is a yes/no that reveals nothing about the other vehicle.
     */
    public function activeAssignmentsForUser(int $userId, ?int $exceptVehicleId = null): bool
    {
        return Vehicle::query()
            ->when($exceptVehicleId, fn (Builder $query) => $query->whereKeyNot($exceptVehicleId))
            ->where('status', '!=', Vehicle::STATUS_RETIRED)
            ->where('assigned_user_id', $userId)
            ->exists();
    }
}
