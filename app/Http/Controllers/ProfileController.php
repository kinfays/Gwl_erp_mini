<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user()->loadMissing([
            'roles',
            'employee.jobTitle',
            'employee.department',
            'employee.region',
            'employee.district',
            'employeeByStaffId.jobTitle',
            'employeeByStaffId.department',
            'employeeByStaffId.region',
            'employeeByStaffId.district',
        ]);
        $employee = $user->employee ?? $user->employeeByStaffId;

        return view('profile.edit', [
            'user' => $user,
            'employee' => $employee,
            'employeeDetails' => $this->employeeDetails($employee),
            'canDeleteAccount' => ! $this->isEmployeeAccount($user),
            'mustChangePassword' => (bool) $user->must_change_password,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user()->loadMissing(['employee', 'employeeByStaffId']);
        $validated = $request->validated();

        $user->fill([
            'email' => $validated['email'],
        ]);

        if ($user->isDirty('email') && Schema::hasColumn('users', 'email_verified_at')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $employee = $user->employee ?? $user->employeeByStaffId;

        if ($employee && $employee->email !== $validated['email']) {
            $employee->forceFill([
                'email' => $validated['email'],
            ])->save();
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        abort_if($this->isEmployeeAccount($request->user()), 403, 'Employee accounts cannot be self-deleted.');

        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    protected function employeeDetails(?Employee $employee): array
    {
        if (! $employee) {
            return [];
        }

        return [
            'Staff ID' => $employee->staff_id,
            'Full Name' => $employee->full_name,
            'Email' => $employee->email,
            'Job Title' => $employee->jobTitle?->job_title_name,
            'Department' => $employee->department?->department_name,
            'Unit' => $employee->unit,
            'Category' => $employee->category,
            'Gender' => $employee->gender,
            'Region' => $employee->region?->region_name,
            'District' => $employee->district?->district_name,
            'Date of Birth' => $this->formatProfileDate($employee->date_of_birth),
            'Date Joined' => $this->formatProfileDate($employee->date_joined),
            'Present Appointment' => $this->formatProfileDate($employee->present_appointment),
            'Status' => $employee->is_active ? 'Active' : 'Inactive',
        ];
    }

    protected function formatProfileDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('d M Y');
        }

        return Carbon::parse($value)->format('d M Y');
    }

    protected function isEmployeeAccount(User $user): bool
    {
        $user->loadMissing(['roles', 'employee', 'employeeByStaffId']);

        return (bool) ($user->employee ?? $user->employeeByStaffId)
            || $user->roles->contains('name', 'employee');
    }
}
