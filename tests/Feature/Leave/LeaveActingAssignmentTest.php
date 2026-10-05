<?php

namespace Tests\Feature\Leave;

use App\Exceptions\Leave\LeaveAlreadyActionedException;
use App\Livewire\Leave\ActingAssignments;
use App\Livewire\Leave\Approvals;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveActingAssignment;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveActingAssignmentService;
use App\Services\Leave\LeaveApprovalChainResolver;
use App\Support\ErpNavigation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Leave\Concerns\BuildsLeaveLetters;
use Tests\TestCase;

/**
 * Acting in a final-approver post: an active assignment makes its user a valid approver for that post inside its window,
 * alongside the holder; outside it, or elsewhere, they are refused. The capacity they acted in is recorded on the request and
 * drives the letter's default signatory ("AG. ..."). Who may set assignments is limited by scope.
 */
class LeaveActingAssignmentTest extends TestCase
{
    use BuildsLeaveLetters;
    use RefreshDatabase;

    protected Employee $applicant;

    protected Employee $manager;

    protected Employee $chief;

    protected Employee $acting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLetterWorld();

        ['applicant' => $this->applicant, 'manager' => $this->manager, 'chief' => $this->chief] = $this->districtChain();
        // Someone who holds no approver role at all, covering the regional chief manager of Greater Accra.
        $this->acting = $this->staff('ACT001', $this->accraOffice, $this->operations, ['departmental_manager']);
    }

    protected function assign(array $overrides = []): LeaveActingAssignment
    {
        return LeaveActingAssignment::query()->create($overrides + [
            'user_id' => $this->userOf($this->acting)->id,
            'acting_for_role' => 'regional_chief_manager',
            'region_id' => $this->accra->id,
            'starts_on' => today()->subDay(),
            'ends_on' => today()->addDays(7),
            'is_active' => true,
        ]);
    }

    protected function pending(): LeaveRequest
    {
        $request = $this->workflow()->submit($this->applicant, $this->leaveData(['start_date' => '2026-05-11', 'end_date' => '2026-05-12']));
        $this->workflow()->recommend($this->manager, $request->fresh(), null, true);

        return $request->fresh();
    }

    protected function chain(): LeaveApprovalChainResolver
    {
        return app(LeaveApprovalChainResolver::class);
    }

    // ================================================================== approving as acting

    public function test_an_acting_assignee_can_give_the_final_approval_and_is_recorded_as_acting(): void
    {
        $this->assign();
        $request = $this->pending();

        $this->assertTrue($this->chain()->canAct($this->userOf($this->acting), $request));
        $this->assertTrue($this->chain()->eligible($request, 'final')->contains('id', $this->userOf($this->acting)->id));

        $this->workflow()->finalDecision($this->acting, $request->fresh(), null, true);

        $request = $request->fresh(['letter']);
        $this->assertSame('Approved', $request->leave_status);
        $this->assertSame('acting', $request->final_approver_capacity);
        $this->assertSame($this->userOf($this->acting)->id, $request->chief_user_id);
        // The letter's default signatory is the acting capacity.
        $this->assertSame('acting', $request->letter->signatory_mode);
        $this->assertSame('AG. REGIONAL CHIEF MANAGER', $request->letter->snapshot['signatory']['title']);
        $this->assertSame('EMPLOYEE ACT001', $request->letter->snapshot['signatory']['name']);
    }

    public function test_the_substantive_holder_is_recorded_as_substantive_even_while_someone_else_is_acting(): void
    {
        $this->assign();
        $request = $this->pending();

        $this->workflow()->finalDecision($this->chief, $request->fresh(), null, true);

        $request = $request->fresh(['letter']);
        $this->assertSame('substantive', $request->final_approver_capacity);
        $this->assertSame('self', $request->letter->signatory_mode);
        $this->assertSame('REGIONAL CHIEF MANAGER', $request->letter->snapshot['signatory']['title']);
    }

    public function test_the_first_to_act_wins_between_the_holder_and_the_acting_assignee(): void
    {
        $this->assign();
        $request = $this->pending();

        $this->workflow()->finalDecision($this->acting, $request->fresh(), null, true);

        $this->expectException(LeaveAlreadyActionedException::class);
        $this->workflow()->finalDecision($this->chief, $request->fresh(), null, true);
    }

    public function test_outside_its_dates_an_assignment_gives_no_right_to_act_and_no_acting_signatory(): void
    {
        $request = $this->pending();

        $cases = [
            'ended yesterday' => ['starts_on' => today()->subDays(10), 'ends_on' => today()->subDay()],
            'starts tomorrow' => ['starts_on' => today()->addDay(), 'ends_on' => today()->addDays(9)],
            'switched off' => ['is_active' => false],
            'another region' => ['region_id' => $this->ashanti->id],
            'another post' => ['acting_for_role' => 'chief_manager', 'region_id' => null, 'department_id' => $this->operations->id],
        ];

        foreach ($cases as $name => $override) {
            LeaveActingAssignment::query()->delete();
            $this->assign($override);

            $this->assertFalse($this->chain()->canAct($this->userOf($this->acting), $request), $name);

            try {
                $this->workflow()->finalDecision($this->acting, $request->fresh(), null, true);
                $this->fail("{$name}: the assignee must be refused.");
            } catch (AuthorizationException) {
                $this->assertSame('Pending Approval', $request->fresh()->leave_status, $name);
            }
        }

        // And the holder approving while an expired assignment exists is not "acting".
        LeaveActingAssignment::query()->delete();
        $this->assign(['starts_on' => today()->subDays(10), 'ends_on' => today()->subDay()]);
        $this->workflow()->finalDecision($this->chief, $request->fresh(), null, true);

        $this->assertSame('self', $request->fresh(['letter'])->letter->signatory_mode);
    }

    public function test_the_window_includes_both_its_first_and_last_day(): void
    {
        $this->assign(['starts_on' => today(), 'ends_on' => today()]);
        $this->assertTrue($this->chain()->canAct($this->userOf($this->acting), $this->pending()));

        $this->travelTo(today()->addDay()->setTime(9, 0));
        $this->assertFalse($this->chain()->canAct($this->userOf($this->acting), LeaveRequest::query()->latest('id')->first()));
    }

    public function test_an_assignee_of_another_regions_post_is_out_of_scope(): void
    {
        $this->assign(['region_id' => $this->ashanti->id]);
        $request = $this->pending();

        $this->actingAs($this->userOf($this->acting));
        Livewire::test(Approvals::class)->call('approveRequest', $request->id)->assertForbidden();
        $this->assertSame('Pending Approval', $request->fresh()->leave_status);
    }

    public function test_the_assignee_sees_the_request_in_their_approvals_queue_only_while_acting(): void
    {
        // Someone with no role at all (not even a manager), so only the assignment gives them the queue.
        $plain = $this->staff('ACT003', $this->accraOffice, $this->operations);
        $user = $this->userOf($plain);
        $assignment = $this->assign(['user_id' => $user->id]);
        $request = $this->pending();

        $this->assertSame([$request->id], $this->chain()->actionableRequests($user)->pluck('id')->all());
        $labels = fn () => collect(app(ErpNavigation::class)->build($user->fresh(), 'leave')['sidebar'])->reject(fn ($item) => ($item['type'] ?? 'item') === 'section')->pluck('label')->all();
        $this->assertContains('Approvals', $labels());

        Livewire::actingAs($user)->test(Approvals::class)->assertSee('Employee EMP001')->call('approveRequest', $request->id);
        $this->assertSame('Approved', $request->fresh()->leave_status);

        // After the window: nothing in the queue and no Approvals entry.
        $other = $this->workflow()->submit($this->applicant, $this->leaveData(['start_date' => '2026-05-13', 'end_date' => '2026-05-14']));
        $this->workflow()->recommend($this->manager, $other->fresh(), null, true);
        $assignment->update(['ends_on' => today()->subDay(), 'starts_on' => today()->subDays(3)]);

        $this->assertSame([], $this->chain()->actionableRequests($user->fresh())->pluck('id')->all());
        $this->assertNotContains('Approvals', $labels());
    }
    public function test_an_assignee_for_a_head_office_chief_manager_post_is_limited_to_that_department(): void
    {
        ['applicant' => $applicant, 'manager' => $manager, 'chief' => $chief] = $this->headOfficeChain();
        $covering = $this->staff('ACT002', $this->headOffice, $this->operations, ['departmental_manager']);
        $assignment = $this->assign(['user_id' => $this->userOf($covering)->id, 'acting_for_role' => 'chief_manager', 'region_id' => null, 'department_id' => $this->finance->id]);

        $request = $this->workflow()->submit($applicant, $this->leaveData(['start_date' => '2026-05-11', 'end_date' => '2026-05-12']));
        $this->workflow()->recommend($manager, $request->fresh(), null, true);
        $this->assertTrue($this->chain()->canAct($this->userOf($covering), $request->fresh()));

        // Covering Operations' chief manager is not covering Finance's.
        $assignment->update(['department_id' => $this->operations->id]);
        $this->assertFalse($this->chain()->canAct($this->userOf($covering), $request->fresh()));
    }

    // ================================================================== managing assignments

    public function test_who_may_manage_assignments(): void
    {
        $service = app(LeaveActingAssignmentService::class);
        $headOfficeHr = $this->userOf($this->staff('HRH001', $this->headOffice, $this->finance, ['hr_headoffice']));
        $accraHr = $this->userOf($this->staff('HRA001', $this->accraOffice, $this->finance, ['hr_region']));
        $admin = $this->userOf($this->staff('ADM001', $this->headOffice, $this->finance, ['admin']));
        $superAdmin = $this->userOf($this->staff('SA001', $this->headOffice, $this->finance, ['super_admin']));

        foreach ([$headOfficeHr, $accraHr, $admin, $superAdmin] as $allowed) {
            $this->assertTrue($service->canManage($allowed));
            $this->actingAs($allowed)->get(route('leave.acting'))->assertOk()->assertSee('Acting Assignments');
        }

        foreach ([$this->userOf($this->applicant), $this->userOf($this->manager), $this->userOf($this->chief)] as $denied) {
            $this->assertFalse($service->canManage($denied));
            $this->actingAs($denied)->get(route('leave.acting'))->assertForbidden();
            Livewire::actingAs($denied)->test(ActingAssignments::class)->assertForbidden();
        }
    }

    public function test_head_office_hr_global_admin_and_super_admin_set_any_assignment(): void
    {
        $service = app(LeaveActingAssignmentService::class);

        foreach (['hr_headoffice', 'admin', 'super_admin'] as $i => $role) {
            $user = $this->userOf($this->staff('MGR'.$i, $this->headOffice, $this->finance, [$role]));

            $service->save($user, ['user_id' => $this->userOf($this->acting)->id, 'acting_for_role' => 'regional_chief_manager', 'region_id' => $this->ashanti->id, 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-20']);
            $service->save($user, ['user_id' => $this->userOf($this->acting)->id, 'acting_for_role' => 'chief_manager', 'department_id' => $this->finance->id, 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-20']);
            $service->save($user, ['user_id' => $this->userOf($this->acting)->id, 'acting_for_role' => 'managing_director', 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-20']);
        }

        $this->assertSame(9, LeaveActingAssignment::query()->count());
    }

    public function test_regional_hr_set_assignments_for_their_own_regions_regional_chief_manager_only(): void
    {
        $service = app(LeaveActingAssignmentService::class);
        $accraHr = $this->userOf($this->staff('HRA001', $this->accraOffice, $this->finance, ['hr_region']));
        $base = ['user_id' => $this->userOf($this->acting)->id, 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-20'];

        $assignment = $service->save($accraHr, $base + ['acting_for_role' => 'regional_chief_manager', 'region_id' => $this->accra->id]);
        $this->assertSame($this->accra->id, $assignment->region_id);

        foreach ([
            $base + ['acting_for_role' => 'regional_chief_manager', 'region_id' => $this->ashanti->id],
            $base + ['acting_for_role' => 'chief_manager', 'department_id' => $this->finance->id],
            $base + ['acting_for_role' => 'managing_director'],
        ] as $outsideScope) {
            try {
                $service->save($accraHr, $outsideScope);
                $this->fail('Regional HR can only cover their own region.');
            } catch (ValidationException|AuthorizationException) {
                $this->assertSame(1, LeaveActingAssignment::query()->count());
            }
        }

        // Another region's assignment can't be switched off or deleted by them either.
        $elsewhere = $this->assign(['region_id' => $this->ashanti->id]);
        $this->expectException(AuthorizationException::class);
        $service->setActive($accraHr, $elsewhere, false);
    }

    public function test_the_page_lists_and_changes_assignments_and_locks_regional_hr_to_their_region(): void
    {
        $accraHr = $this->userOf($this->staff('HRA001', $this->accraOffice, $this->finance, ['hr_region']));
        $ashantiAssignment = $this->assign(['region_id' => $this->ashanti->id]);

        $page = Livewire::actingAs($accraHr)->test(ActingAssignments::class)
            ->assertSet('regionId', (string) $this->accra->id)
            ->assertDontSee('Ashanti')
            ->set('userId', (string) $this->userOf($this->acting)->id)
            ->set('startsOn', '2026-05-10')
            ->set('endsOn', '2026-05-20')
            ->call('save')
            ->assertHasNoErrors();

        $mine = LeaveActingAssignment::query()->where('region_id', $this->accra->id)->sole();
        $page->call('toggle', $mine->id);
        $this->assertFalse($mine->fresh()->is_active);
        $page->call('delete', $mine->id);
        $this->assertNull(LeaveActingAssignment::query()->find($mine->id));

        // The other region's row is out of reach even by id.
        Livewire::actingAs($accraHr)->test(ActingAssignments::class)->call('toggle', $ashantiAssignment->id)->assertForbidden();
        $this->assertTrue($ashantiAssignment->fresh()->is_active);
    }

    public function test_every_change_is_audited_and_bad_input_is_rejected(): void
    {
        $service = app(LeaveActingAssignmentService::class);
        $admin = $this->userOf($this->staff('ADM001', $this->headOffice, $this->finance, ['admin']));
        $this->actingAs($admin);

        $assignment = $service->save($admin, ['user_id' => $this->userOf($this->acting)->id, 'acting_for_role' => 'regional_chief_manager', 'region_id' => $this->accra->id, 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-20']);
        $service->save($admin, ['user_id' => $this->userOf($this->acting)->id, 'acting_for_role' => 'regional_chief_manager', 'region_id' => $this->accra->id, 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-25'], $assignment);
        $service->setActive($admin, $assignment->fresh(), false);
        $service->delete($admin, $assignment->fresh());

        $this->assertSame(
            ['leave_acting_assignment_created', 'leave_acting_assignment_updated', 'leave_acting_assignment_disabled', 'leave_acting_assignment_deleted'],
            AuditLog::query()->where('module', 'leave')->where('action', 'like', 'leave_acting_assignment_%')->orderBy('id')->pluck('action')->all()
        );
        $updated = AuditLog::query()->where('action', 'leave_acting_assignment_updated')->sole();
        $this->assertSame('2026-05-20', $updated->old_values['ends_on']);
        $this->assertSame('2026-05-25', $updated->new_values['ends_on']);

        foreach ([
            ['acting_for_role' => 'regional_chief_manager', 'region_id' => null],        // a regional post needs its region
            ['acting_for_role' => 'chief_manager', 'department_id' => null],             // a chief manager post needs its department
            ['acting_for_role' => 'district_manager'],                                   // not a final-approver post
            ['acting_for_role' => 'managing_director', 'starts_on' => '2026-05-20', 'ends_on' => '2026-05-10'],
            ['acting_for_role' => 'managing_director', 'user_id' => 999999],
        ] as $bad) {
            try {
                $service->save($admin, $bad + ['user_id' => $this->userOf($this->acting)->id, 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-20']);
                $this->fail('That assignment should have been rejected.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        $this->assertSame(0, LeaveActingAssignment::query()->count());
    }

    public function test_the_record_of_who_approved_survives_the_assignment_being_deleted(): void
    {
        $assignment = $this->assign();
        $request = $this->pending();
        $this->workflow()->finalDecision($this->acting, $request->fresh(), null, true);

        $assignment->delete();

        $request = $request->fresh(['letter']);
        $this->assertSame('acting', $request->final_approver_capacity);
        $this->assertSame('AG. REGIONAL CHIEF MANAGER', $request->letter->snapshot['signatory']['title']);
        $this->assertInstanceOf(User::class, $request->chiefUser);
    }
}
