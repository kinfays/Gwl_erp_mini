<?php

namespace App\Http\Controllers\Staff;

use App\Exports\Staff\EmployeesExport;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Staff\EmployeeDirectory;
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

    public function edit(Employee $employee): View
    {
        abort_if(! Employee::visibleInErp()->whereKey($employee->id)->exists(), 404);

        return view('staff.form', compact('employee'));
    }

    public function import(): View
    {
        return view('staff.import');
    }

    public function departments(): View
    {
        return view('staff.departments');
    }

    public function toggleStatus(Employee $employee): RedirectResponse
    {
        abort_if(! Employee::visibleInErp()->whereKey($employee->id)->exists(), 404);

        $old = $employee->toArray();

        $employee->update([
            'is_active' => ! $employee->is_active,
        ]);

        AuditLog::record(
            $employee->is_active ? 'activate_employee' : 'deactivate_employee',
            'staff',
            'employees',
            $employee->id,
            $old,
            $employee->fresh()->toArray()
        );

        return back()->with('success', 'Employee status updated successfully.');
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
            'employees_' . now()->format('Y_m_d_His') . '.xlsx'
        );
    }
}
