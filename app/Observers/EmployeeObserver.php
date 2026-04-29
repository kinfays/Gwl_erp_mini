<?php

namespace App\Observers;

use App\Models\Employee;
use App\Models\User;
use App\Models\Role;
use App\Notifications\InviteUserNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;


class EmployeeObserver
{
    public function created(Employee $employee): void
    {
        /*
        Log::info('EmployeeObserver fired', [
            'employee_id' => $employee->id,
            'staff_id' => $employee->staff_id,
        ]); */

        // Prevent duplicate users
        if (User::where('staff_id', $employee->staff_id)->exists()) {
            return;
        }

        $payload = [
            'staff_id'    => $employee->staff_id,
            'employee_id' => $employee->id,
            'email'       => $employee->email,
            'password'    => Hash::make(Str::random(20)),
            'is_active'   => $employee->is_active,
        ];

        if (Schema::hasColumn('users', 'full_name')) {
            $payload['full_name'] = $employee->full_name;
        }

        $user = User::create($payload);

        // Attach default employee role
        $employeeRole = Role::where('name', 'employee')->first();
        if ($employeeRole) {
            $user->roles()->syncWithoutDetaching([$employeeRole->id]);
        }

        // Send invite email
        $this->sendInvite($user);
    }

    public function updated(Employee $employee): void
    {
        $user = User::where('staff_id', $employee->staff_id)->first();

        if (! $user) {
            return;
        }

        $user->update([
            'email'       => $employee->email,
            'employee_id' => $employee->id,
            'is_active'   => $employee->is_active,
        ]);

        if (Schema::hasColumn('users', 'full_name')) {
            $user->update([
                'full_name' => $employee->full_name,
            ]);
        }
    }

    protected function sendInvite(User $user): void
    {
        $token = Password::broker()->createToken($user);

        $url = url(route('password.reset', [
            'token' => $token,
            'email' => $user->email,
        ], false));

        $user->notify(new InviteUserNotification(
            $url,
            $user->staff_id
        ));
    }
}
