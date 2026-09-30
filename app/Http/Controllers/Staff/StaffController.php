<?php

namespace App\Http\Controllers\Staff;

use App\Exports\Staff\EmployeesExport;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Staff\EmployeeDirectory;
use App\Support\UserProfilePayload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class StaffController extends Controller
{
    public function index(): View
    {
        return view('staff.index');
    }

    public function create(): View
    {
        return view('staff.form', [
            'employee' => null,
        ]);
    }

    public function edit(Request $request, Employee $employee, EmployeeDirectory $directory): View
    {
        abort_if(! Employee::visibleTo($request->user())->whereKey($employee->id)->exists(), 404);
        abort_unless($directory->canAccess($request->user(), $employee), 403);

        return view('staff.form', compact('employee'));
    }

    public function import(): View
    {
        return view('staff.import');
    }

    public function reports(): View
    {
        return view('staff.reports');
    }

    public function departments(): View
    {
        return view('staff.departments');
    }

    public function regions(): View
    {
        return view('staff.regions');
    }

    public function locations(): View
    {
        return view('staff.locations');
    }

    public function jobTitles(): View
    {
        return view('staff.job-titles');
    }

    public function toggleStatus(Request $request, Employee $employee, EmployeeDirectory $directory): RedirectResponse
    {
        abort_if(! Employee::visibleTo($request->user())->whereKey($employee->id)->exists(), 404);
        abort_unless($directory->canAccess($request->user(), $employee), 403);

        $old = $employee->toArray();
        $activating = ! $employee->is_active;
        $reasonRule = ['in:'.implode(',', array_keys(Employee::DEACTIVATION_REASONS))];
        $validated = $request->validate([
            'deactivation_reason' => $activating
                ? ['nullable', ...$reasonRule]
                : ['required', ...$reasonRule],
        ]);

        $employee->update([
            'is_active' => $activating,
            'deactivation_reason' => $activating ? null : $validated['deactivation_reason'],
        ]);

        AuditLog::record(
            $employee->is_active ? 'activate_employee' : 'deactivate_employee',
            'staff',
            'employees',
            $employee->id,
            $old,
            $employee->fresh()->toArray()
        );

        return back()->with('success', $employee->is_active
            ? 'Employee activated successfully.'
            : 'Employee deactivated successfully.');
    }

    public function showUser(Request $request, User $user, EmployeeDirectory $directory, UserProfilePayload $profiles)
    {
        // A super_admin account doesn't exist for anyone else.
        abort_unless(User::query()->visibleTo($request->user())->whereKey($user->id)->exists(), 404);

        $employee = $user->employee ?? $user->employeeByStaffId;

        abort_if(! $employee, 404);
        abort_if(! $directory->queryFor($request->user())->whereKey($employee->id)->exists(), 403);

        return response()->json($profiles->for($user));
    }

    public function export(Request $request, EmployeeDirectory $directory)
    {
        $employees = $directory
            ->applyFilters($directory->queryFor($request->user()), $request->only([
                'search',
                'department_id',
                'category',
                'location_type',
                'status',
            ]))
            ->orderBy('full_name')
            ->get();

        AuditLog::record(
            'export_employees',
            'staff',
            'employees',
            null,
            null,
            $request->only(['search', 'department_id', 'category', 'location_type', 'status'])
        );

        return Excel::download(
            new EmployeesExport($employees),
            'employees_'.now()->format('Y_m_d_His').'.xlsx'
        );
    }
}
