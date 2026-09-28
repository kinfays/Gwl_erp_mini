<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveWorkflowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * LeaveWorkflowService stamps submitted_at, recommended_at and decided_at as a request moves
 * through the district chain (requester → district manager → regional chief manager).
 */
class LeaveStageTimestampsTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $requester;

    protected Employee $manager;

    protected Employee $chief;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        $region = Region::query()->create(['region_name' => 'Greater Accra']);
        $district = District::query()->create(['district_name' => 'Accra Central', 'region_id' => $region->id]);

        $this->requester = $this->createEmployee($district, 'EMP501');
        $this->manager = $this->createEmployee($district, 'MGR501', 'district_manager');
        $this->chief = $this->createEmployee($district, 'CHF501', 'regional_chief_manager');
    }

    public function test_submission_recommendation_and_approval_are_each_stamped(): void
    {
        $request = $this->at('2026-09-01 08:00', fn () => $this->workflow()->submit($this->requester, $this->leaveData()));

        $this->assertStages($request, '2026-09-01 08:00:00', null, null);

        $this->at('2026-09-01 13:00', fn () => $this->workflow()->recommend($this->manager, $request->fresh(), 'Covered', true));
        $this->assertStages($request, '2026-09-01 08:00:00', '2026-09-01 13:00:00', null);

        $this->at('2026-09-01 17:00', fn () => $this->workflow()->finalDecision($this->chief, $request->fresh(), 'Enjoy', true));
        $request->refresh();

        $this->assertStages($request, '2026-09-01 08:00:00', '2026-09-01 13:00:00', '2026-09-01 17:00:00');
        $this->assertSame('Approved', $request->leave_status);
        $this->assertEquals(5, $request->managerResponseHours());
        $this->assertEquals(4, $request->approverHours());
        $this->assertEquals(9, $request->cycleHours());
    }

    public function test_a_managers_rejection_is_also_the_decision(): void
    {
        $request = $this->at('2026-09-01 08:00', fn () => $this->workflow()->submit($this->requester, $this->leaveData()));

        $this->at('2026-09-01 11:00', fn () => $this->workflow()->recommend($this->manager, $request->fresh(), 'Short-staffed', false));
        $request->refresh();

        $this->assertSame('Denied', $request->leave_status);
        $this->assertStages($request, '2026-09-01 08:00:00', '2026-09-01 11:00:00', '2026-09-01 11:00:00');
        $this->assertEquals(3, $request->managerResponseHours());
        $this->assertNull($request->approverHours());
        $this->assertEquals(3, $request->cycleHours());
    }

    public function test_the_chief_denying_after_a_recommendation_stamps_the_decision(): void
    {
        $request = $this->at('2026-09-01 08:00', fn () => $this->workflow()->submit($this->requester, $this->leaveData()));
        $this->at('2026-09-01 10:00', fn () => $this->workflow()->recommend($this->manager, $request->fresh(), null, true));
        $this->at('2026-09-02 10:00', fn () => $this->workflow()->finalDecision($this->chief, $request->fresh(), 'Clashes with audit', false));
        $request->refresh();

        $this->assertSame('Denied', $request->leave_status);
        $this->assertEquals(24, $request->approverHours());
        $this->assertEquals(26, $request->cycleHours());
    }

    public function test_a_planned_request_starts_waiting_when_it_is_submitted(): void
    {
        $request = $this->at('2026-09-01 08:00', fn () => $this->workflow()->savePlanned($this->requester, $this->leaveData()));
        $this->assertStages($request, null, null, null);

        // Submitted two days after it was planned.
        $this->at('2026-09-03 08:00', fn () => $this->workflow()->submitExisting($this->requester, $request->fresh(), $this->leaveData()));
        $this->at('2026-09-03 10:00', fn () => $this->workflow()->recommend($this->manager, $request->fresh(), null, true));
        $request->refresh();

        $this->assertStages($request, '2026-09-03 08:00:00', '2026-09-03 10:00:00', null);
        $this->assertEquals(2, $request->managerResponseHours());
    }

    public function test_editing_a_waiting_request_keeps_its_submission_time(): void
    {
        $request = $this->at('2026-09-01 08:00', fn () => $this->workflow()->submit($this->requester, $this->leaveData()));

        $this->at('2026-09-01 12:00', fn () => $this->workflow()->submitExisting(
            $this->requester,
            $request->fresh(),
            $this->leaveData(['leave_details' => 'Moved by a day'])
        ));

        $this->assertStages($request, '2026-09-01 08:00:00', null, null);
    }

    public function test_a_managers_own_request_skips_the_manager_stage(): void
    {
        $request = $this->at('2026-09-01 08:00', fn () => $this->workflow()->submit($this->manager, $this->leaveData()));

        $this->assertSame('Recommended', $request->manager_recommendation);
        $this->assertStages($request, '2026-09-01 08:00:00', '2026-09-01 08:00:00', null);

        $this->at('2026-09-01 14:00', fn () => $this->workflow()->finalDecision($this->chief, $request->fresh(), null, true));
        $request->refresh();

        $this->assertNull($request->managerResponseHours());
        $this->assertEquals(6, $request->approverHours());
        $this->assertEquals(6, $request->cycleHours());
    }

    public function test_reopening_a_denied_request_clears_its_stage_times(): void
    {
        $request = $this->at('2026-09-01 08:00', fn () => $this->workflow()->submit($this->requester, $this->leaveData()));
        $this->at('2026-09-01 09:00', fn () => $this->workflow()->recommend($this->manager, $request->fresh(), null, false));

        $this->at('2026-09-02 08:00', fn () => $this->workflow()->reopen($this->requester, $request->fresh()));
        $this->assertStages($request, null, null, null);

        $this->at('2026-09-04 08:00', fn () => $this->workflow()->submitExisting($this->requester, $request->fresh(), $this->leaveData()));
        $this->assertStages($request, '2026-09-04 08:00:00', null, null);
    }

    protected function workflow(): LeaveWorkflowService
    {
        return app(LeaveWorkflowService::class);
    }

    protected function at(string $moment, callable $callback): mixed
    {
        $this->travelTo(Carbon::parse($moment));

        try {
            return $callback();
        } finally {
            $this->travelBack();
        }
    }

    protected function assertStages(LeaveRequest $request, ?string $submitted, ?string $recommended, ?string $decided): void
    {
        $request->refresh();

        $this->assertSame($submitted, $request->submitted_at?->toDateTimeString(), 'submitted_at');
        $this->assertSame($recommended, $request->recommended_at?->toDateTimeString(), 'recommended_at');
        $this->assertSame($decided, $request->decided_at?->toDateTimeString(), 'decided_at');
    }

    protected function leaveData(array $overrides = []): array
    {
        return [
            'leave_type' => 'Annual',
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-07',
            'leave_details' => 'Family visit',
            ...$overrides,
        ];
    }

    protected function createEmployee(District $district, string $staffId, ?string $role = null): Employee
    {
        $employee = Employee::withoutEvents(function () use ($district, $staffId) {
            $department = Department::query()->firstOrCreate(['department_name' => 'Operations']);
            $jobTitle = JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer']);

            return Employee::query()->create([
                'staff_id' => $staffId,
                'full_name' => 'Employee '.$staffId,
                'gender' => 'Female',
                'category' => 'Senior Staff',
                'email' => strtolower($staffId).'@example.com',
                'job_title_id' => $jobTitle->id,
                'department_id' => $department->id,
                'region_id' => $district->region_id,
                'district_id' => $district->id,
                'location_type' => 'District',
                'date_of_birth' => '1990-01-01',
                'date_joined' => '2020-01-06',
                'is_active' => true,
            ]);
        });

        if ($role) {
            $user = User::query()->create([
                'staff_id' => $employee->staff_id,
                'employee_id' => $employee->id,
                'full_name' => $employee->full_name,
                'email' => $employee->email,
                'password' => Hash::make('abc12'),
                'is_active' => true,
                'must_change_password' => false,
            ]);

            $user->roles()->attach(Role::query()->firstOrCreate(
                ['name' => $role],
                ['display_name' => str($role)->replace('_', ' ')->title()->toString(), 'is_system' => true]
            ));
        }

        return $employee;
    }
}
