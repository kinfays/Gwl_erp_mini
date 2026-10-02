<?php

namespace App\Support;

use App\Models\User;

class UserProfilePayload
{
    public function for(User $user): array
    {
        $user->load([
            'roles:id,name,display_name',
            'employee.jobTitle:id,job_title_name',
            'employee.department:id,department_name',
            'employee.region:id,region_name',
            'employee.district:id,district_name',
            'employeeByStaffId.jobTitle:id,job_title_name',
            'employeeByStaffId.department:id,department_name',
            'employeeByStaffId.region:id,region_name',
            'employeeByStaffId.district:id,district_name',
        ]);

        $employee = $user->employee ?? $user->employeeByStaffId;

        return [
            'user' => [
                'id' => $user->id,
                'staff_id' => $user->staff_id,
                'full_name' => $user->full_name ?? $employee?->full_name,
                'email' => $user->email,
                'is_active' => (bool) $user->is_active,
                'last_login_at' => optional($user->last_login_at)->toDateTimeString(),
                'roles' => $user->visibleRoles()->map(fn ($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                ])->values(),
            ],
            'employee' => $employee ? [
                'staff_id' => $employee->staff_id,
                'full_name' => $employee->full_name,
                'email' => $employee->email,
                'gender' => $employee->gender,
                'category' => $employee->category,
                'grade' => $employee->grade,
                'location_type' => $employee->location_type,
                'unit' => $employee->unit,
                'present_appointment' => $employee->present_appointment,
                'date_of_birth' => optional($employee->date_of_birth)->toDateString(),
                'age' => $employee->age,
                'retirement_date' => optional($employee->retirement_date)->toDateString(),
                'date_joined' => optional($employee->date_joined)->toDateString(),
                'status' => $employee->is_active ? 'Active' : 'Inactive',
                'deactivation_reason' => $employee->deactivation_reason,
                'deactivation_reason_label' => $employee->deactivation_reason_label,

                'job_title' => $employee->jobTitle?->job_title_name,
                'department' => $employee->department?->department_name,
                'region' => $employee->region?->region_name,
                'district' => $employee->district?->district_name,

                'annual_leave_days' => $employee->annual_leave_days,
                'casual_leave_days' => $employee->casual_leave_days,
                'parental_days' => $employee->parental_days,
            ] : null,
        ];
    }
}
