<?php

namespace Tests\Feature\Auth;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileMyAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_only_their_own_assets(): void
    {
        [$user, $employee] = $this->userWithEmployee('STF100');
        $colleague = $this->employee('STF101');
        $this->asset(['asset_name' => 'Mine Laptop', 'serial_number' => 'SN-MINE', 'assigned_to_employee_id' => $employee->id]);
        $this->asset(['asset_name' => 'Colleague Laptop', 'serial_number' => 'SN-THEIRS', 'assigned_to_employee_id' => $colleague->id]);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('My ICT Assets')
            ->assertSee('Mine Laptop')
            ->assertSee('SN-MINE')
            ->assertDontSee('Colleague Laptop')
            ->assertDontSee('SN-THEIRS');
    }

    public function test_user_without_employee_record_sees_empty_state(): void
    {
        $user = User::query()->create([
            'staff_id' => 'STF200',
            'email' => 'noemp@example.com',
            'password' => Hash::make('abc12'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('My ICT Assets')
            ->assertSee('No employee record is linked to this account.');
    }

    public function test_user_without_assets_sees_empty_message(): void
    {
        [$user] = $this->userWithEmployee('STF300');

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('No ICT assets are assigned to you.');
    }

    public function test_plain_role_sees_card_but_not_price_or_imei(): void
    {
        [$user, $employee] = $this->userWithEmployee('STF400');
        $role = Role::query()->create(['name' => 'employee', 'display_name' => 'Employee', 'is_system' => true]);
        $user->roles()->attach($role);
        $this->asset([
            'asset_name' => 'Handset',
            'assigned_to_employee_id' => $employee->id,
            'device_category' => IctAsset::DEVICE_CATEGORY_PHONE,
            'asset_type' => 'Smartphone',
            'imei' => '356938035643809',
        ]);

        $response = $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $response->assertSee('My ICT Assets')
            ->assertSee('Handset')
            ->assertSee('To report a problem or request a change, contact the ICT team.')
            ->assertDontSee('356938035643809')
            ->assertDontSee('Purchase price')
            ->assertDontSee('IMEI')
            ->assertDontSee(route('assets.home'));
    }

    protected function userWithEmployee(string $staffId): array
    {
        $employee = $this->employee($staffId);
        $user = User::query()->create([
            'staff_id' => $staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('abc12'),
            'is_active' => true,
            'must_change_password' => false,
            'employee_id' => $employee->id,
        ]);

        return [$user, $employee];
    }

    protected function employee(string $staffId): Employee
    {
        $region = Region::query()->first() ?? Region::query()->create(['region_name' => 'Greater Accra']);
        $district = District::query()->first() ?? District::query()->create([
            'district_name' => 'Accra West Regional Office',
            'region_id' => $region->id,
        ]);
        $department = Department::query()->first() ?? Department::query()->create(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->first() ?? JobTitle::query()->create(['job_title_name' => 'Officer']);

        return Employee::withoutEvents(fn () => Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'Person '.$staffId,
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => $jobTitle->id,
            'department_id' => $department->id,
            'region_id' => $region->id,
            'district_id' => $district->id,
            'location_type' => 'Region',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-05-04',
        ]));
    }

    protected function asset(array $overrides = []): IctAsset
    {
        return IctAsset::query()->create(array_merge([
            'asset_name' => 'Asset',
            'serial_number' => 'SN-'.uniqid(),
            'asset_type' => 'PC',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
        ], $overrides));
    }
}
