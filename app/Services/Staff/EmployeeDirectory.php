<?php

namespace App\Services\Staff;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EmployeeDirectory
{
    public function queryFor(User $user): Builder
    {
        $employee = $user->employee ?? $user->employeeByStaffId;

        $query = Employee::query()
            ->visibleInErp()
            ->with([
                'department',
                'jobTitle',
                'region',
                'district.region',
                'leaveBalances' => fn ($balanceQuery) => $balanceQuery
                    ->where('leave_type', 'Annual')
                    ->where('current_year', now()->year),
            ])
            ->withCount([
                'leaveRequests as on_leave_count' => fn ($leaveQuery) => $leaveQuery
                    ->where('leave_status', 'Approved')
                    ->whereDate('start_date', '<=', today())
                    ->whereDate('end_date', '>=', today()),
            ]);

        if ($user->hasRoles('super_admin', 'hr_headoffice')) {
            return $query;
        }

        if ($user->hasRoles('admin', 'hr_region')) {
            return $employee?->region_id
                ? $query->where('region_id', $employee->region_id)
                : $query;
        }

        if ($user->hasRoles('regional_chief_manager')) {
            return $employee?->region_id
                ? $query->where('region_id', $employee->region_id)
                : $query->whereRaw('1 = 0');
        }

        if ($user->hasRoles('district_manager')) {
            return $employee?->district_id
                ? $query->where('district_id', $employee->district_id)
                : $query->whereRaw('1 = 0');
        }

        if ($user->hasRoles('chief_manager', 'departmental_manager')) {
            $query->when($employee?->department_id, fn (Builder $builder) => $builder->where('department_id', $employee->department_id));
            $query->when($employee?->region_id, fn (Builder $builder) => $builder->where('region_id', $employee->region_id));
            $query->when($employee?->district_id, fn (Builder $builder) => $builder->where('district_id', $employee->district_id));

            return $query;
        }

        if ($user->hasRoles('manager')) {
            $query->when($employee?->department_id, fn (Builder $builder) => $builder->where('department_id', $employee->department_id));
            $query->when($employee?->unit, fn (Builder $builder) => $builder->where('unit', $employee->unit));
            $query->when($employee?->region_id, fn (Builder $builder) => $builder->where('region_id', $employee->region_id));
            $query->when($employee?->district_id, fn (Builder $builder) => $builder->where('district_id', $employee->district_id));

            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    public function applyFilters(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $departmentId = $filters['department_id'] ?? null;
        $category = trim((string) ($filters['category'] ?? ''));
        $locationType = trim((string) ($filters['location_type'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));

        $query->when($search !== '', function (Builder $builder) use ($search) {
            $builder->where(function (Builder $searchQuery) use ($search) {
                $searchQuery
                    ->where('full_name', 'like', '%' . $search . '%')
                    ->orWhere('staff_id', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%');
            });
        });

        $query->when($departmentId, fn (Builder $builder) => $builder->where('department_id', $departmentId));
        $query->when($category !== '', fn (Builder $builder) => $builder->where('category', $category));
        $query->when($locationType !== '', fn (Builder $builder) => $builder->where('location_type', $locationType));

        return match ($status) {
            'active' => $query
                ->where('is_active', true)
                ->whereDoesntHave('leaveRequests', $this->currentLeaveConstraint()),
            'inactive' => $query->where('is_active', false),
            'on_leave' => $query
                ->where('is_active', true)
                ->whereHas('leaveRequests', $this->currentLeaveConstraint()),
            default => $query,
        };
    }

    protected function currentLeaveConstraint(): \Closure
    {
        return fn (Builder $builder) => $builder
            ->where('leave_status', 'Approved')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today());
    }
}
