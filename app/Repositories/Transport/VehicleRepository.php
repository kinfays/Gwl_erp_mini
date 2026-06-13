<?php

namespace App\Repositories\Transport;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;

class VehicleRepository
{
    public function query(): Builder
    {
        return Vehicle::query();
    }

    public function findByUuid(string $uuid): Vehicle
    {
        return Vehicle::query()->where('uuid', $uuid)->firstOrFail();
    }

    public function assignedTo(User $user): ?Vehicle
    {
        return Vehicle::query()
            ->where('status', '!=', Vehicle::STATUS_RETIRED)
            ->where(function (Builder $query) use ($user): void {
                $query->where('assigned_user_id', $user->id)
                    ->orWhere('assigned_driver_id', $user->id);
            })
            ->with(['assignedUser', 'assignedDriver', 'department'])
            ->first();
    }

    public function activeAssignmentsForUser(int $userId, ?int $exceptVehicleId = null): bool
    {
        return Vehicle::query()
            ->when($exceptVehicleId, fn (Builder $query) => $query->whereKeyNot($exceptVehicleId))
            ->where('status', '!=', Vehicle::STATUS_RETIRED)
            ->where('assigned_user_id', $userId)
            ->exists();
    }
}
