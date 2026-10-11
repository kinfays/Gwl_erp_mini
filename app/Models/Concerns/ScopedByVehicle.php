<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\Transport\TransportScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * For the Transport tables that hang off a vehicle (expenses, mileage logs, issues, maintenance records, assignment
 * history): `Model::query()->visibleTo($user)` keeps only the rows of vehicles the user may reach. The rule itself lives
 * in TransportScope; start every read of these tables with this scope.
 */
trait ScopedByVehicle
{
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(TransportScope::class)->records($query, $user);
    }
}
