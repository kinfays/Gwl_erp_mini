<?php

namespace Tests\Feature\Visitors;

use App\Livewire\Visitors\Kiosk;
use App\Livewire\Visitors\TodayLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\ModuleAccess;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class VisitorCheckInTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_check_in_issues_a_checkout_code_that_self_checkout_accepts(): void
    {
        $staff = $this->createEmployee();

        Livewire::test(Kiosk::class)
            ->set('visitor_name', 'Ama Mensah')
            ->set('phone', '0241234567')
            ->set('staff_id', $staff->id)
            ->set('purpose', 'Bill payment enquiry')
            ->set('signature', 'data:image/png;base64,in')
            ->call('submit')
            ->assertSet('success', true);

        $visitor = Visitor::query()->sole();

        $this->assertMatchesRegularExpression('/^\d{1,3}$/', $visitor->checkout_code);
        $this->assertNull($visitor->check_out_at);

        Livewire::test(Kiosk::class)
            ->set('selfCheckoutCode', $visitor->checkout_code)
            ->call('findSelfCheckout')
            ->assertSet('selfCheckoutVisitorId', $visitor->id)
            ->set('selfCheckoutSignature', 'data:image/png;base64,out')
            ->call('confirmSelfCheckout');

        $visitor->refresh();

        $this->assertSame('self', $visitor->checked_out_by);
        $this->assertNotNull($visitor->check_out_at);
        $this->assertSame('data:image/png;base64,out', $visitor->signature);
    }

    public function test_receptionist_checkout_keeps_the_check_in_signature(): void
    {
        $this->actingAs($this->createReceptionistUser());
        $visitor = $this->createVisitor($this->createEmployee(), ['check_in_at' => now()->subHour()]);

        Livewire::test(TodayLog::class)->call('checkOut', $visitor->id);

        $visitor->refresh();

        $this->assertSame('receptionist', $visitor->checked_out_by);
        $this->assertNotNull($visitor->check_out_at);
        $this->assertSame('data:image/png;base64,test', $visitor->signature);
    }

    public function test_auto_checkout_only_closes_visits_still_open_today(): void
    {
        $this->travelTo(now()->setTime(18, 0));
        $staff = $this->createEmployee();

        $openToday = $this->createVisitor($staff, ['check_in_at' => now()->setTime(10, 0)]);
        $closedToday = $this->createVisitor($staff, [
            'check_in_at' => now()->setTime(9, 0),
            'check_out_at' => now()->setTime(9, 30),
            'checked_out_by' => 'self',
        ]);
        $openYesterday = $this->createVisitor($staff, ['check_in_at' => now()->subDay()->setTime(11, 0)]);

        $this->artisan('gwcl:auto-checkout-visitors')
            ->expectsOutput('1 visitor(s) auto-checked out.')
            ->assertSuccessful();

        $this->assertSame('auto', $openToday->refresh()->checked_out_by);
        $this->assertSame('self', $closedToday->refresh()->checked_out_by);
        $this->assertNull($openYesterday->refresh()->check_out_at);
    }

    protected function createReceptionistUser(): User
    {
        $role = Role::query()->create([
            'name' => 'receptionist',
            'display_name' => 'Receptionist',
            'is_system' => true,
        ]);

        ModuleAccess::query()->create([
            'role_id' => $role->id,
            'module' => 'visitors',
            'can_access' => true,
        ]);

        $user = User::query()->create([
            'full_name' => 'Reception Desk',
            'email' => 'reception@example.com',
            'staff_id' => 'REC001',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $user->roles()->attach($role);

        return $user;
    }

    protected function createEmployee(): Employee
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $district = District::query()->create([
            'district_name' => 'Accra West Regional Office',
            'region_id' => $region->id,
        ]);
        $department = Department::query()->create(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->create(['job_title_name' => 'HR Officer']);

        return Employee::withoutEvents(fn () => Employee::query()->create([
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
        ]));
    }

    protected function createVisitor(Employee $staff, array $overrides = []): Visitor
    {
        return Visitor::query()->create(array_merge([
            'visitor_name' => 'Test Visitor',
            'phone' => '0200000000',
            'staff_id' => $staff->id,
            'purpose' => 'Meeting',
            'signature' => 'data:image/png;base64,test',
            'checkout_code' => '123',
            'check_in_at' => now(),
        ], $overrides));
    }
}
