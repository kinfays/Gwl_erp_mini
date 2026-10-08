<?php

namespace App\Services\HealthSafety;

use App\Models\Employee;
use App\Models\User;

/**
 * The one definition of "which part of the register is this user's role entitled to", shared by IncidentVisibility
 * (incidents, view_incidents) and EquipmentScope (equipment, view_equipment) so the region / district / Head Office rules
 * cannot drift apart.
 *
 * super_admin and hs_manager see every region; so does anyone at Head Office who holds the permission. hs_officer,
 * regional_chief_manager and any other role holding it see their own region; district_manager (without one of those)
 * their own district. Head Office is a district whose staff share the Head Office district's region_id, so it is
 * recognised by location_type, never by region alone. No employee record or no region means nothing.
 */
class ActorScope
{
    public const ALL = 'all';
    public const REGION = 'region';
    public const DISTRICT = 'district';
    public const NONE = 'none';

    /**
     * @return array{level: string, id: int|null}
     */
    public function resolve(User $user, string $viewPermission): array
    {
        if (! $this->can($user, $viewPermission)) {
            return ['level' => self::NONE, 'id' => null];
        }

        if ($user->hasRoles('super_admin', 'hs_manager')) {
            return ['level' => self::ALL, 'id' => null];
        }

        $employee = $this->employeeOf($user);

        if ($employee?->location_type === 'HeadOffice') {
            return ['level' => self::ALL, 'id' => null];
        }

        if (! $user->hasRoles('hs_officer', 'regional_chief_manager') && $user->hasRoles('district_manager')) {
            return $employee?->district_id
                ? ['level' => self::DISTRICT, 'id' => (int) $employee->district_id]
                : ['level' => self::NONE, 'id' => null];
        }

        return $employee?->region_id
            ? ['level' => self::REGION, 'id' => (int) $employee->region_id]
            : ['level' => self::NONE, 'id' => null];
    }

    public function can(User $user, string $slug): bool
    {
        return $user->hasRoles('super_admin') || $user->hasPermission($slug);
    }

    public function employeeOf(User $user): ?Employee
    {
        return $user->employee ?? $user->employeeByStaffId;
    }
}
