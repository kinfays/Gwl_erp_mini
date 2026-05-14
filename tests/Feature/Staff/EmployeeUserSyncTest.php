<?php

namespace Tests\Feature\Staff;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmployeeUserSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_employee_automatically_creates_an_active_user(): void
    {
        Notification::fake();
        $employee = $this->createEmployee();

        $user = User::query()->where('staff_id', $employee->staff_id)->first();

        $this->assertNotNull($user);
        $this->assertSame($employee->id, $user->employee_id);
        $this->assertSame($employee->email, $user->email);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check(User::DEFAULT_PASSWORD, $user->password));
        $this->assertTrue($user->roles()->doesntExist());
        $this->assertInviteUsesSetPasswordMode($user);
    }

    public function test_updating_an_employee_repairs_a_missing_user(): void
    {
        Notification::fake();
        $employee = Employee::withoutEvents(fn () => $this->createEmployee([
            'staff_id' => '654321',
            'email' => 'existing.employee@example.com',
        ]));

        $this->assertDatabaseMissing('users', ['staff_id' => $employee->staff_id]);

        $employee->update(['full_name' => 'Existing Employee Updated']);

        $user = User::query()->where('staff_id', $employee->staff_id)->first();

        $this->assertNotNull($user);
        $this->assertSame($employee->id, $user->employee_id);
        $this->assertSame('Existing Employee Updated', $user->full_name);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check(User::DEFAULT_PASSWORD, $user->password));
        $this->assertTrue($user->roles()->doesntExist());
        $this->assertInviteUsesSetPasswordMode($user);
    }

    protected function createEmployee(array $overrides = []): Employee
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $district = District::query()->create([
            'district_name' => 'Accra West Regional Office',
            'region_id' => $region->id,
        ]);
        $department = Department::query()->create(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->create(['job_title_name' => 'HR Officer']);

        return Employee::query()->create(array_merge([
            'staff_id' => '123456',
            'full_name' => 'Frank Bin Frank',
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => 'fbfra@test.com',
            'job_title_id' => $jobTitle->id,
            'department_id' => $department->id,
            'region_id' => $region->id,
            'district_id' => $district->id,
            'location_type' => 'Region',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-05-04',
        ], $overrides));
    }

    protected function assertInviteUsesSetPasswordMode(User $user): void
    {
        Notification::assertSentTo($user, InviteUserNotification::class, function (InviteUserNotification $notification) use ($user) {
            return str_contains($notification->setPasswordUrl, 'set_password=1')
                && str_contains($notification->setPasswordUrl, 'staff_id='.$user->staff_id);
        });
    }
}
