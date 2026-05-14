<?php

namespace App\Observers;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;

class EmployeeObserver
{
    public function created(Employee $employee): void
    {
        $this->syncUser($employee, sendInviteForNewUser: true);
    }

    public function updated(Employee $employee): void
    {
        $this->syncUser($employee, sendInviteForNewUser: true);
    }

    protected function syncUser(Employee $employee, bool $sendInviteForNewUser = false): User
    {
        $user = User::query()
            ->where('employee_id', $employee->id)
            ->orWhere('staff_id', $employee->staff_id)
            ->first();

        if (! $user) {
            $user = new User([
                'staff_id' => $employee->staff_id,
            ]);
            $user->password = Hash::make(User::DEFAULT_PASSWORD);

            if (Schema::hasColumn('users', 'must_change_password')) {
                $user->must_change_password = true;
            }
        }

        $payload = [
            'staff_id' => $employee->staff_id,
            'employee_id' => $employee->id,
            'email' => $employee->email,
            'is_active' => $employee->is_active ?? true,
        ];

        if (Schema::hasColumn('users', 'full_name')) {
            $payload['full_name'] = $employee->full_name;
        }

        $user->fill($payload);
        $wasNewUser = ! $user->exists;
        $user->save();

        if ($wasNewUser && $sendInviteForNewUser) {
            $this->sendInvite($user);
        }

        return $user;
    }

    protected function sendInvite(User $user): void
    {
        $token = Password::broker()->createToken($user);

        $url = url(route('password.reset', [
            'token' => $token,
            'email' => $user->email,
            'staff_id' => $user->staff_id,
            'set_password' => true,
        ], false));

        $user->notify(new InviteUserNotification(
            $url,
            $user->staff_id
        ));
    }
}
