<?php

namespace Tests\Feature\Letters;

use App\Livewire\Letters\ActiveLetters;
use App\Models\Employee;
use App\Models\LetterDispatchBatch;
use App\Models\LetterRemark;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

/**
 * Phase 3: managers and chief managers hold letters. Eligibility is by the letters.view permission (not by role name),
 * reviewers are scoped by office (D6), and a manager holding a letter records remarks as themselves.
 */
class ManagerHoldersTest extends TestCase
{
    use BuildsLettersOrg;
    use RefreshDatabase;

    protected Employee $hrSec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLettersOrg();
        $this->hrSec = $this->letterStaff('HR001', $this->accraOffice);
    }

    private function as(Employee $employee)
    {
        $this->actingAs($this->letterUserOf($employee));

        return Livewire::test(ActiveLetters::class);
    }

    private function ids($query): array
    {
        return $query->pluck('id')->sort()->values()->all();
    }

    private function idsOf(Employee ...$employees): array
    {
        return collect($employees)->pluck('id')->sort()->values()->all();
    }

    // ---- eligibility: by permission, not by role name -----------------------------------------------------

    public function test_every_role_holding_letters_view_with_the_module_is_an_eligible_recipient(): void
    {
        $people = [
            $this->letterStaff('SEC02', $this->accraOffice),
            $this->letterStaff('MGR01', $this->accraOffice, ['manager']),
            $this->letterStaff('DEP01', $this->accraOffice, ['departmental_manager']),
            $this->letterStaff('DIS01', $this->temaDistrict, ['district_manager']),
            $this->letterStaff('CHF01', $this->accraOffice, ['chief_manager']),
            $this->letterStaff('RCM01', $this->accraOffice, ['regional_chief_manager']),
        ];

        $this->assertSame($this->idsOf(...$people), $this->ids($this->lettersWorkflow()->recipientsQuery(null, $this->hrSec)));
    }

    public function test_who_is_not_eligible(): void
    {
        $plain = $this->letterStaff('EMP01', $this->accraOffice, ['leave_applicant']);
        $inactiveEmployee = $this->letterStaff('OFF01', $this->accraOffice, employeeActive: false);
        $inactiveUser = $this->letterStaff('OFF02', $this->accraOffice);
        User::query()->where('employee_id', $inactiveUser->id)->update(['is_active' => false]);
        $admin = $this->letterStaff('ADM01', $this->accraOffice, ['super_admin']);

        // A role that holds letters.view but was never given the Letters module cannot be handed a letter.
        $noModule = Role::query()->create(['name' => 'viewers_without_module', 'display_name' => 'No module', 'is_system' => false]);
        $noModule->permissions()->attach(Permission::query()->where('name', 'letters.view')->value('id'));
        $noModuleStaff = $this->letterStaff('NOM01', $this->accraOffice, ['viewers_without_module']);

        $eligible = $this->ids($this->lettersWorkflow()->recipientsQuery(null, $this->hrSec));

        $this->assertSame([], array_intersect($eligible, $this->idsOf($plain, $inactiveEmployee, $inactiveUser, $admin, $noModuleStaff, $this->hrSec)));
    }

    public function test_eligibility_follows_the_permission_so_an_admin_can_extend_it_from_the_role_editor(): void
    {
        $clerk = Role::query()->create(['name' => 'records_clerk', 'display_name' => 'Records clerk', 'is_system' => false]);
        ModuleAccess::query()->create(['role_id' => $clerk->id, 'module' => Permission::MODULE_LETTERS, 'can_access' => true]);
        $person = $this->letterStaff('CLK01', $this->accraOffice, ['records_clerk']);

        $this->assertNotContains($person->id, $this->ids($this->lettersWorkflow()->recipientsQuery(null, $this->hrSec)));

        $clerk->permissions()->attach(Permission::query()->where('name', 'letters.view')->value('id'));
        $this->assertContains($person->id, $this->ids($this->lettersWorkflow()->recipientsQuery(null, $this->hrSec)));

        $clerk->permissions()->detach();
        $this->assertNotContains($person->id, $this->ids($this->lettersWorkflow()->recipientsQuery(null, $this->hrSec)));
    }

    public function test_an_account_linked_to_the_employee_by_staff_id_only_is_eligible_too(): void
    {
        $manager = $this->letterStaff('MGR01', $this->accraOffice, ['manager']);
        User::query()->where('employee_id', $manager->id)->update(['employee_id' => null]);

        $this->assertContains($manager->id, $this->ids($this->lettersWorkflow()->recipientsQuery(null, $this->hrSec)));
    }

    public function test_the_search_matches_name_staff_id_and_department(): void
    {
        $finance = $this->letterStaff('FIN01', $this->accraOffice, ['manager'], department: $this->finance);
        $registry = $this->letterStaff('REG01', $this->accraOffice, ['manager']);

        $this->assertSame([$finance->id], $this->ids($this->lettersWorkflow()->recipientsQuery('FIN01', $this->hrSec)), 'staff id');
        $this->assertSame([$finance->id], $this->ids($this->lettersWorkflow()->recipientsQuery('Finance', $this->hrSec)), 'department');
        $this->assertSame([$registry->id], $this->ids($this->lettersWorkflow()->recipientsQuery('Employee REG01', $this->hrSec)), 'name');
        $this->assertSame([], $this->ids($this->lettersWorkflow()->recipientsQuery('nobody by this name', $this->hrSec)));
    }

    public function test_the_location_chips_narrow_the_list(): void
    {
        $sameRegion = $this->letterStaff('SAM01', $this->accraOffice, ['manager']);
        $sameRegionDistrict = $this->letterStaff('SAM02', $this->temaDistrict, ['district_manager']);
        $headOffice = $this->letterStaff('HQ001', $this->headOffice, ['manager']);
        $elsewhere = $this->letterStaff('NOR01', $this->northOffice, ['manager']);
        $workflow = $this->lettersWorkflow();

        // A regional-office secretary: "my location" is her region without Head Office, which shares it.
        $this->assertSame($this->idsOf($sameRegion, $sameRegionDistrict), $this->ids($workflow->recipientsQuery(null, $this->hrSec, 'mine')));
        $this->assertSame([$headOffice->id], $this->ids($workflow->recipientsQuery(null, $this->hrSec, 'head_office')));
        $this->assertSame($this->idsOf($sameRegion, $sameRegionDistrict, $headOffice, $elsewhere), $this->ids($workflow->recipientsQuery(null, $this->hrSec, 'any')));
        $this->assertSame($this->ids($workflow->recipientsQuery(null, $this->hrSec, 'any')), $this->ids($workflow->recipientsQuery(null, $this->hrSec)), 'no scope means everyone');

        // A Head Office secretary: "my location" is Head Office.
        $hoSec = $this->letterStaff('HQ002', $this->headOffice);
        $this->assertSame($this->idsOf($headOffice), $this->ids($workflow->recipientsQuery(null, $hoSec, 'mine')));
    }

    // ---- handing a letter to a manager --------------------------------------------------------------------

    public function test_a_manager_can_be_dispatched_to_confirm_remark_and_pass_the_letter_on(): void
    {
        $manager = $this->letterStaff('MGR01', $this->accraOffice, ['manager']);
        $letter = $this->createLetter($this->hrSec, ['subject' => 'For the manager']);

        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $manager);

        $this->assertTrue($this->lettersWorkflow()->visibleLettersQuery($manager)->whereKey($letter->id)->exists());
        $this->assertFalse($this->lettersWorkflow()->canDispatch($letter, $manager), 'not until the hardcopy is confirmed');

        $this->as($manager)
            ->assertSee('For the manager')
            ->call('openLetter', $letter->id, true)
            ->assertSet('confirmPrompt', true)
            ->call('confirmHardcopy')
            ->assertDispatched('toast', type: 'success', message: 'Hardcopy receipt confirmed.');

        $this->assertTrue($this->lettersWorkflow()->canDispatch($letter, $manager));

        $this->lettersWorkflow()->addRemark($letter, $manager, ['manager_id' => $manager->id, 'remark_content' => 'Approved']);
        $this->lettersWorkflow()->dispatch($letter, $manager, $this->hrSec);

        $this->assertNotNull($this->lettersWorkflow()->pendingIncomingRoute($letter, $this->hrSec));
    }

    public function test_a_transmittal_can_go_to_a_manager(): void
    {
        $manager = $this->letterStaff('MGR01', $this->accraOffice, ['manager']);
        $letters = $this->createLetters($this->hrSec, 2);

        $batch = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $manager, $letters->pluck('id')->all());

        $this->assertSame($manager->id, $batch->to_secretariat_id);
        $this->assertSame(2, $this->lettersWorkflow()->pendingIncomingCount($manager));
    }

    public function test_a_crafted_recipient_id_is_refused_by_the_service(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $letters = $this->createLetters($this->hrSec, 2);

        $noModule = Role::query()->create(['name' => 'viewers_without_module', 'display_name' => 'No module', 'is_system' => false]);
        $noModule->permissions()->attach(Permission::query()->where('name', 'letters.view')->value('id'));

        $bad = [
            'an employee without letters.view' => $this->letterStaff('EMP01', $this->accraOffice, ['leave_applicant']),
            'an inactive manager' => $this->letterStaff('OFF01', $this->accraOffice, ['manager'], employeeActive: false),
            'a role without the Letters module' => $this->letterStaff('NOM01', $this->accraOffice, ['viewers_without_module']),
        ];

        foreach ($bad as $label => $recipient) {
            try {
                $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $recipient);
                $this->fail("dispatch() must refuse {$label}.");
            } catch (\RuntimeException $e) {
                $this->assertSame('The selected recipient cannot receive letters.', $e->getMessage(), $label);
            }

            try {
                $this->lettersWorkflow()->dispatchBatch($this->hrSec, $recipient, $letters->pluck('id')->all());
                $this->fail("dispatchBatch() must refuse {$label}.");
            } catch (\RuntimeException $e) {
                $this->assertSame('The selected recipient cannot receive letters.', $e->getMessage(), $label);
            }
        }

        $this->assertSame(0, \App\Models\RoutingHistory::query()->count());
        $this->assertSame(0, LetterDispatchBatch::query()->count());
    }

    public function test_a_crafted_recipient_id_in_the_ui_shows_the_message_instead_of_dispatching(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $other = $this->createLetter($this->hrSec, ['subject' => 'Other']);
        $employee = $this->letterStaff('EMP01', $this->accraOffice, ['leave_applicant']);

        $this->as($this->hrSec)
            ->call('openLetter', $letter->id)
            ->set('dispatchToId', $employee->id)
            ->call('dispatchLetter')
            ->assertDispatched('toast', type: 'error', message: 'The selected recipient cannot receive letters.');

        $this->as($this->hrSec)
            ->set('selected', [$other->id])
            ->call('openBulkDispatch')
            ->set('bulkDispatchToId', $employee->id)
            ->call('dispatchSelected')
            ->assertDispatched('toast', type: 'error', message: 'The selected recipient cannot receive letters.');

        $this->assertSame(0, \App\Models\RoutingHistory::query()->count());
    }

    // ---- D6: reviewers are scoped by office -----------------------------------------------------------------

    public function test_reviewers_are_scoped_by_location_type_then_department_or_region(): void
    {
        $hoMgrRegistry = $this->letterStaff('HM001', $this->headOffice, ['manager']);
        $hoMgrFinance = $this->letterStaff('HM002', $this->headOffice, ['departmental_manager'], department: $this->finance);
        $hoChiefRegistry = $this->letterStaff('HC001', $this->headOffice, ['chief_manager']);
        $hoChiefFinance = $this->letterStaff('HC002', $this->headOffice, ['chief_manager'], department: $this->finance);
        $accraMgr = $this->letterStaff('AM001', $this->accraOffice, ['departmental_manager']);
        $accraChief = $this->letterStaff('AC001', $this->accraOffice, ['regional_chief_manager']);
        $temaMgr = $this->letterStaff('TM001', $this->temaDistrict, ['district_manager']);
        $northMgr = $this->letterStaff('NM001', $this->northOffice, ['manager']);
        $northChief = $this->letterStaff('NC001', $this->northOffice, ['regional_chief_manager']);
        $workflow = $this->lettersWorkflow();

        // Head Office (which shares Greater Accra's region_id with its regional office): the same department at Head Office only.
        $hoSec = $this->letterStaff('HS001', $this->headOffice);
        $this->assertSame([$hoMgrRegistry->id], $this->ids($workflow->regionalManagersQuery($hoSec)), 'HQ managers: own department at HQ only');
        $this->assertSame([$hoChiefRegistry->id], $this->ids($workflow->regionalChiefManagersQuery($hoSec)));

        $hoFinanceSec = $this->letterStaff('HS002', $this->headOffice, department: $this->finance);
        $this->assertSame([$hoMgrFinance->id], $this->ids($workflow->regionalManagersQuery($hoFinanceSec)));
        $this->assertSame([$hoChiefFinance->id], $this->ids($workflow->regionalChiefManagersQuery($hoFinanceSec)));

        // The regional office and its districts: the region, never Head Office.
        $this->assertSame($this->idsOf($accraMgr, $temaMgr), $this->ids($workflow->regionalManagersQuery($this->hrSec)));
        $this->assertSame([$accraChief->id], $this->ids($workflow->regionalChiefManagersQuery($this->hrSec)));

        $temaSec = $this->letterStaff('TS001', $this->temaDistrict);
        $this->assertSame($this->idsOf($accraMgr, $temaMgr), $this->ids($workflow->regionalManagersQuery($temaSec)));

        // Another region sees only its own.
        $northSec = $this->letterStaff('NS001', $this->northOffice);
        $this->assertSame([$northMgr->id], $this->ids($workflow->regionalManagersQuery($northSec)));
        $this->assertSame([$northChief->id], $this->ids($workflow->regionalChiefManagersQuery($northSec)));
    }

    public function test_the_reviewer_lists_never_include_inactive_people_or_the_managing_director(): void
    {
        $this->letterStaff('OFF01', $this->accraOffice, ['manager'], employeeActive: false);
        $this->letterStaff('MD001', $this->accraOffice, ['managing_director']);
        $active = $this->letterStaff('MGR01', $this->accraOffice, ['manager']);

        $this->assertSame([$active->id], $this->ids($this->lettersWorkflow()->regionalManagersQuery($this->hrSec)));
        $this->assertSame([], $this->ids($this->lettersWorkflow()->regionalChiefManagersQuery($this->hrSec)));
    }

    public function test_a_reviewer_from_another_office_is_refused_in_the_form(): void
    {
        $this->letterStaff('HM001', $this->headOffice, ['manager']);
        $outsider = Employee::query()->where('staff_id', 'HM001')->first();
        $letter = $this->createLetter($this->hrSec);

        $this->as($this->hrSec)
            ->call('openLetter', $letter->id)
            ->set('remarkManagerId', $outsider->id)
            ->set('remarkContent', 'Noted')
            ->call('addRemark')
            ->assertHasErrors(['remarkManagerId']);

        $this->assertSame(0, LetterRemark::query()->count());
    }

    // ---- remarks by a manager holding the letter ------------------------------------------------------------

    private function managerHolding(string $role = 'manager', string $staffId = 'MGR01'): array
    {
        $manager = $this->letterStaff($staffId, $this->accraOffice, [$role]);
        $letter = $this->createLetter($this->hrSec, ['subject' => 'Held by '.$role]);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $manager);
        $this->lettersWorkflow()->confirmHardcopy($letter, $manager);

        return [$manager, $letter];
    }

    public function test_the_reviewer_tier_follows_the_role(): void
    {
        foreach (['manager' => 'manager', 'departmental_manager' => 'manager', 'district_manager' => 'manager', 'chief_manager' => 'chief', 'regional_chief_manager' => 'chief'] as $role => $tier) {
            $this->assertSame($tier, $this->lettersWorkflow()->reviewerTier($this->letterStaff('T'.strtoupper(substr($role, 0, 3)).rand(10, 99), $this->accraOffice, [$role])), $role);
        }

        $this->assertNull($this->lettersWorkflow()->reviewerTier($this->hrSec));
        $this->assertNull($this->lettersWorkflow()->reviewerTier($this->letterStaff('LV001', $this->accraOffice, ['letters_viewer'])));
    }

    public function test_the_form_locks_a_manager_as_the_reviewer_and_hides_the_secretary_field(): void
    {
        [$manager, $letter] = $this->managerHolding();

        $this->as($manager)
            ->call('openLetter', $letter->id)
            ->assertSee('Add a remark')
            ->assertSee('Your remark')
            ->assertSeeHtml('value="Employee MGR01"')
            ->assertDontSee('Secretary remarks')
            ->assertDontSee('Type to search chief manager');
    }

    public function test_a_chief_manager_is_locked_into_the_chief_manager_field(): void
    {
        [$chief, $letter] = $this->managerHolding('chief_manager', 'CHF01');

        $this->as($chief)
            ->call('openLetter', $letter->id)
            ->assertSee('Chief Manager')
            ->assertSeeHtml('value="Employee CHF01"')
            ->assertDontSee('Secretary remarks');
    }

    public function test_a_secretary_keeps_the_current_form(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $this->letterStaff('MGR01', $this->accraOffice, ['manager']);

        $this->as($this->hrSec)
            ->call('openLetter', $letter->id)
            ->assertSee('Secretary remarks')
            ->assertSee('Manager remarks')
            ->assertDontSee('Your remark');
    }

    public function test_a_managers_remark_is_recorded_as_theirs_whatever_the_client_sends(): void
    {
        [$manager, $letter] = $this->managerHolding();
        $other = $this->letterStaff('MGR02', $this->accraOffice, ['manager']);
        $chief = $this->letterStaff('CHF01', $this->accraOffice, ['chief_manager']);

        $this->as($manager)
            ->call('openLetter', $letter->id)
            ->set('remarkContent', 'Approved, refer to Materials')
            ->set('remarkManagerId', $other->id)        // tampered
            ->set('remarkChiefManagerId', $chief->id)   // tampered
            ->set('secretaryRemarkContent', 'sneaky')   // tampered
            ->call('addRemark')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success', message: 'Remark added.');

        $remark = LetterRemark::query()->sole();
        $this->assertSame($manager->id, $remark->manager_id);
        $this->assertNull($remark->chief_manager_id);
        $this->assertNull($remark->secretary_remark_content);
        $this->assertSame($manager->id, $remark->author_id);
        $this->assertSame('Approved, refer to Materials', $remark->remark_content);
    }

    public function test_a_chief_managers_remark_is_recorded_in_the_chief_field(): void
    {
        [$chief, $letter] = $this->managerHolding('regional_chief_manager', 'RCM01');

        $this->as($chief)
            ->call('openLetter', $letter->id)
            ->set('remarkContent', 'Proceed')
            ->call('addRemark')
            ->assertHasNoErrors();

        $remark = LetterRemark::query()->sole();
        $this->assertSame($chief->id, $remark->chief_manager_id);
        $this->assertNull($remark->manager_id);
    }

    public function test_a_manager_can_edit_their_remark_and_it_stays_theirs(): void
    {
        [$manager, $letter] = $this->managerHolding();
        $remark = $this->lettersWorkflow()->addRemark($letter, $manager, ['manager_id' => $manager->id, 'remark_content' => 'First']);
        $other = $this->letterStaff('MGR02', $this->accraOffice, ['manager']);

        $this->as($manager)
            ->call('openLetter', $letter->id)
            ->call('startEditRemark', $remark->id)
            ->assertSee('Recorded as you.')
            ->set('editingRemarkContent', 'Edited')
            ->set('editingRemarkManagerId', $other->id) // tampered
            ->call('updateRemark')
            ->assertHasNoErrors();

        $this->assertSame('Edited', $remark->fresh()->remark_content);
        $this->assertSame($manager->id, $remark->fresh()->manager_id);
    }

    public function test_the_service_rejects_a_reviewer_that_does_not_match_the_acting_manager(): void
    {
        [$manager, $letter] = $this->managerHolding();
        $other = $this->letterStaff('MGR02', $this->accraOffice, ['manager']);
        $chief = $this->letterStaff('CHF01', $this->accraOffice, ['chief_manager']);

        $cases = [
            'another manager' => [['manager_id' => $other->id, 'remark_content' => 'x'], 'As a manager you can only record remarks as yourself.'],
            'no reviewer' => [['remark_content' => 'x'], 'As a manager you can only record remarks as yourself.'],
            'a chief instead' => [['chief_manager_id' => $chief->id, 'remark_content' => 'x'], 'As a manager you can only record remarks as yourself.'],
            'themselves and a chief' => [['manager_id' => $manager->id, 'chief_manager_id' => $chief->id, 'remark_content' => 'x'], 'As a manager you can only record remarks as yourself.'],
            'a secretary remark' => [['manager_id' => $manager->id, 'remark_content' => 'x', 'secretary_remark_content' => 'typed for me'], 'Secretary remarks can only be added by a secretary. Write your remark in the manager remarks.'],
            'an empty remark' => [['manager_id' => $manager->id, 'remark_content' => '  '], 'Enter your remark.'],
        ];

        foreach ($cases as $label => [$data, $message]) {
            try {
                $this->lettersWorkflow()->addRemark($letter, $manager, $data);
                $this->fail("{$label} must be refused.");
            } catch (\RuntimeException $e) {
                $this->assertSame($message, $e->getMessage(), $label);
            }
        }

        $this->assertSame(0, LetterRemark::query()->count());

        $this->lettersWorkflow()->addRemark($letter, $manager, ['manager_id' => $manager->id, 'remark_content' => 'Mine']);
        $this->assertSame(1, LetterRemark::query()->count());
    }

    public function test_the_service_rejects_a_mismatched_reviewer_for_a_chief_manager_too(): void
    {
        [$chief, $letter] = $this->managerHolding('chief_manager', 'CHF01');
        $mgr = $this->letterStaff('MGR01', $this->accraOffice, ['manager']);

        foreach ([['manager_id' => $mgr->id, 'remark_content' => 'x'], ['manager_id' => $chief->id, 'remark_content' => 'x']] as $data) {
            try {
                $this->lettersWorkflow()->addRemark($letter, $chief, $data);
                $this->fail('A chief manager records remarks in the Chief Manager field, as themselves.');
            } catch (\RuntimeException $e) {
                $this->assertSame('As a chief manager you can only record remarks as yourself.', $e->getMessage());
            }
        }

        $this->lettersWorkflow()->addRemark($letter, $chief, ['chief_manager_id' => $chief->id, 'remark_content' => 'Mine']);
        $this->assertSame($chief->id, LetterRemark::query()->sole()->chief_manager_id);
    }

    public function test_secretaries_are_not_bound_to_themselves(): void
    {
        $letter = $this->createLetter($this->hrSec);
        $mgr = $this->letterStaff('MGR01', $this->accraOffice, ['manager']);

        $remark = $this->lettersWorkflow()->addRemark($letter, $this->hrSec, [
            'manager_id' => $mgr->id,
            'remark_content' => 'The manager said yes',
            'secretary_remark_content' => 'Typed by the secretary',
        ]);

        $this->assertSame($mgr->id, $remark->manager_id);
        $this->assertSame($this->hrSec->id, $remark->author_id);
    }

    // ---- the picker -----------------------------------------------------------------------------------------

    public function test_the_picker_groups_secretaries_and_managers_with_name_department_and_location(): void
    {
        $this->letterStaff('SEC02', $this->accraOffice);
        $this->letterStaff('MGR01', $this->accraOffice, ['manager'], department: $this->finance);
        $letter = $this->createLetter($this->hrSec);

        $picker = $this->lettersWorkflow()->recipientPicker($this->hrSec, collect([$letter]), null, 'mine');

        $this->assertSame(['Employee SEC02'], $picker['secretaries']->pluck('full_name')->all());
        $this->assertSame(['Employee MGR01'], $picker['managers']->pluck('full_name')->all());
        $this->assertSame('Employee MGR01 · Finance · Accra Regional Office', $this->lettersWorkflow()->recipientLabel($picker['managers']->first()));
        $this->assertNull($picker['previous']);
        $this->assertTrue($picker['recent']->isEmpty());
    }

    public function test_the_dispatch_tab_shows_the_grouped_picker_with_chips(): void
    {
        $this->letterStaff('SEC02', $this->accraOffice);
        $this->letterStaff('MGR01', $this->accraOffice, ['manager'], department: $this->finance);
        $letter = $this->createLetter($this->hrSec);

        $this->as($this->hrSec)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'dispatch')
            ->assertSee('My location')
            ->assertSee('Head Office')
            ->assertSee('Any')
            ->assertSeeHtml('<optgroup label="Secretaries">')
            ->assertSeeHtml('<optgroup label="Managers">')
            ->assertSee('Employee MGR01 · Finance · Accra Regional Office')
            ->assertSet('dispatchScope', 'mine');
    }

    public function test_the_location_chip_changes_who_is_listed(): void
    {
        $this->letterStaff('HQ001', $this->headOffice, ['manager']);
        $letter = $this->createLetter($this->hrSec);

        $component = $this->as($this->hrSec)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'dispatch')
            ->assertDontSee('Employee HQ001');

        $component->set('dispatchScope', 'head_office')->assertSee('Employee HQ001 · Registry · Head Office');
        $component->set('dispatchScope', 'any')->assertSee('Employee HQ001');
        $component->set('dispatchScope', 'mine')->assertDontSee('Employee HQ001');
    }

    public function test_return_to_previous_holder_and_recent_recipients_are_pinned_on_top(): void
    {
        $manager = $this->letterStaff('MGR01', $this->accraOffice, ['manager']);
        $sec2 = $this->letterStaff('SEC02', $this->accraOffice);
        $this->letterStaff('SEC03', $this->accraOffice);

        // hr recently sent something to sec2, and received this letter from the manager.
        $old = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($old, $this->hrSec, $sec2);
        $letter = $this->createLetter($this->hrSec, ['subject' => 'Came back']);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $manager);
        $this->lettersWorkflow()->confirmHardcopy($letter, $manager);
        $this->lettersWorkflow()->dispatch($letter, $manager, $this->hrSec);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->hrSec);

        $picker = $this->lettersWorkflow()->recipientPicker($this->hrSec, collect([$letter]), null, 'mine');

        $this->assertSame($manager->id, $picker['previous']?->id, 'the manager handed it to hr');
        $this->assertSame([$sec2->id], $picker['recent']->pluck('id')->all(), 'previous holder is not repeated under recent');
        $this->assertNotContains($manager->id, $picker['managers']->pluck('id')->all(), 'pinned people are not listed twice');
        $this->assertNotContains($sec2->id, $picker['secretaries']->pluck('id')->all());
        $this->assertSame(['Employee SEC03'], $picker['secretaries']->pluck('full_name')->all());

        $this->as($this->hrSec)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'dispatch')
            ->assertSee('Return to previous holder: Employee MGR01')
            ->assertSeeHtml('<optgroup label="Previous holder">')
            ->assertSeeHtml('<optgroup label="Recent recipients">');
    }

    public function test_no_previous_holder_when_the_letters_came_from_different_people_or_were_recorded_by_the_actor(): void
    {
        $a = $this->letterStaff('SEC02', $this->accraOffice);
        $b = $this->letterStaff('SEC03', $this->accraOffice);
        $workflow = $this->lettersWorkflow();

        $fromA = $this->createLetter($a);
        $workflow->dispatch($fromA, $a, $this->hrSec);
        $fromB = $this->createLetter($b);
        $workflow->dispatch($fromB, $b, $this->hrSec);
        $mine = $this->createLetter($this->hrSec);

        $this->assertSame($a->id, $workflow->previousHolderFor(collect([$fromA]), $this->hrSec)?->id);
        $this->assertNull($workflow->previousHolderFor(collect([$fromA, $fromB]), $this->hrSec), 'different senders');
        $this->assertNull($workflow->previousHolderFor(collect([$fromA, $mine]), $this->hrSec), 'one was recorded by the actor');
        $this->assertNull($workflow->previousHolderFor(collect([$mine]), $this->hrSec));
        $this->assertNull($workflow->previousHolderFor(collect(), $this->hrSec));
    }

    public function test_the_bulk_drawer_uses_the_same_picker_and_previous_holder_for_a_shared_sender(): void
    {
        $manager = $this->letterStaff('MGR01', $this->accraOffice, ['manager']);
        $letters = $this->createLetters($this->hrSec, 2, 'Returning');
        foreach ($letters as $letter) {
            $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $manager);
            $this->lettersWorkflow()->confirmHardcopy($letter, $manager);
        }

        $this->as($manager)
            ->set('selected', $letters->pluck('id')->all())
            ->call('openBulkDispatch')
            ->assertSee('Return to previous holder: Employee HR001')
            ->assertSee('My location')
            ->set('bulkDispatchToId', $this->hrSec->id)
            ->call('dispatchSelected')
            ->assertHasNoErrors();

        $this->assertSame(1, LetterDispatchBatch::query()->count());
        $this->assertSame(2, $this->lettersWorkflow()->pendingIncomingCount($this->hrSec));
    }

    public function test_the_picker_is_only_built_when_its_panel_is_open(): void
    {
        $letter = $this->createLetter($this->hrSec);

        $component = $this->as($this->hrSec)->assertViewHas('picker', null)->assertViewHas('bulkPicker', null);
        $component->call('openLetter', $letter->id)->assertViewHas('picker', null)->set('detailTab', 'dispatch')->assertViewHas('picker', fn ($picker) => is_array($picker));
    }
}
