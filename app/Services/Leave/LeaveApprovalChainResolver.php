<?php

namespace App\Services\Leave;

use App\Models\Employee;

class LeaveApprovalChainResolver
{
    /**
     * Returns [managerEmployee, chiefApproverEmployee]
     * Throws if no valid chain found.
     */
    public function resolve(Employee $employee): array
    {
        return match ($employee->location_type) {
            'HeadOffice' => $this->resolveHeadOffice($employee),
            'Region' => $this->resolveRegion($employee),
            'District' => $this->resolveDistrict($employee),
            default => throw new \RuntimeException('Invalid location type'),
        };
    }

    protected function resolveHeadOffice(Employee $employee): array
    {
        // Final approver: Departmental Chief-Manager (chief_manager) in same department
        $chief = $this->findByRoleAndScope('chief_manager', [
            'department_id' => $employee->department_id,
        ]);

        // Recommender:
        // if unit exists => Unit Manager (manager) in same dept + unit
        if (!empty($employee->unit)) {
            $manager = $this->findByRoleAndScope('manager', [
                'department_id' => $employee->department_id,
                'unit' => $employee->unit,
            ]);

            return [$manager, $chief];
        }

        // else => Departmental Manager (departmental_manager)
        $manager = $this->findByRoleAndScope('departmental_manager', [
            'department_id' => $employee->department_id,
        ]);

        return [$manager, $chief];
    }

    protected function resolveRegion(Employee $employee): array
    {
        $manager = $this->findByRoleAndScope('departmental_manager', [
            'department_id' => $employee->department_id,
            'region_id' => $employee->region_id,
        ]);

        $chief = $this->findByRoleAndScope('regional_chief_manager', [
            'region_id' => $employee->region_id,
        ]);

        return [$manager, $chief];
    }

    protected function resolveDistrict(Employee $employee): array
    {
        $manager = $this->findByRoleAndScope('district_manager', [
            'district_id' => $employee->district_id,
        ]);

        $chief = $this->findByRoleAndScope('regional_chief_manager', [
            'region_id' => $employee->region_id,
        ]);

        return [$manager, $chief];
    }

    protected function findByRoleAndScope(string $roleSlug, array $filters): Employee
    {
        $q = Employee::query()->where('is_active', true);

        foreach ($filters as $key => $val) {
            $q->where($key, $val);
        }

        // Employee → User → Roles relationship
        $q->whereHas('user.roles', fn ($r) => $r->where('name', $roleSlug));

        $found = $q->first();

        if (! $found) {
            throw new \RuntimeException("Approver not found for role: {$roleSlug}");
        }

        return $found;
    }
}