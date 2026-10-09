<?php

namespace Tests\Feature\Uac;

use App\Livewire\Staff\EmployeeForm;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Import\DataImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use App\Services\Uac\RoleGrantPolicy;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * "Global Admin" is the `admin` slug under a new name, held only by Head Office staff: assignment is refused for
 * anyone elsewhere, and moving the holder out of Head Office takes the role away (audited and announced).
 */
class GlobalAdminTest extends TestCase
{
    use BuildsUacOrg;
    use RefreshDatabase;

    protected User $super;

    protected User $globalAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->buildUacOrg();

        $this->super = $this->person('SA001', $this->headOffice, ['super_admin']);
        $this->globalAdmin = $this->person('GA001', $this->headOffice, ['admin']);
    }

    // ---------------------------------------------------------------- The name

    public function test_the_admin_role_is_shown_as_global_admin_and_slug_based_checks_still_pass(): void
    {
        $this->assertSame('admin', $this->role('admin')->name);
        $this->assertSame('Global Admin', $this->role('admin')->display_name);
        $this->assertSame('Global Admin', $this->globalAdmin->displayRoleNames());
        $this->assertTrue($this->globalAdmin->hasRoles('admin'));
        $this->assertTrue($this->globalAdmin->isGlobalAdmin());
        // Health & Safety and the Regional Blog are open to every member of staff (anyone may report an incident or read their
        // region's blog), Global Admin included.
        $this->assertEqualsCanonicalizing(['uac', 'assets', 'leave', 'health_safety', 'blog'], $this->globalAdmin->getAccessibleModules());

        // Route middleware names the slug: it still lets a Global Admin in, and the badge uses the new name.
        $this->actingAs($this->globalAdmin)->get(route('uac.users'))->assertOk()->assertSee('Global Admin');
        $this->actingAs($this->globalAdmin)->get(route('uac.roles'))->assertOk()->assertSee('Global Admin');

        $this->actingAs($this->person('ICT001', $this->headOffice, ['ict_team']))->get(route('uac.roles'))->assertForbidden();
    }

    public function test_the_rename_and_classification_migrations_are_idempotent_and_restore_the_names_and_flags(): void
    {
        Role::query()->where('name', 'admin')->update(['display_name' => 'Admin']);
        Role::query()->update(['ict_assignable' => false, 'is_protected' => false]);

        foreach (['2026_09_30_000002_rename_admin_role_to_global_admin', '2026_09_30_000001_add_ict_assignable_and_is_protected_to_roles_table'] as $file) {
            $migration = require database_path("migrations/{$file}.php");
            $migration->up();
            $migration->up();
        }

        $this->assertSame('Global Admin', $this->role('admin')->display_name);
        $this->assertTrue($this->role('hr_region')->ict_assignable);
        $this->assertFalse($this->role('credit_union_officer')->ict_assignable);
        $this->assertTrue($this->role('admin')->is_protected);
        $this->assertFalse($this->role('ict_team')->is_protected);
    }

    // ---------------------------------------------------------------- Head Office only

    public function test_global_admin_can_only_be_assigned_to_head_office_employees(): void
    {
        $headOfficeStaff = $this->person('EMP001', $this->headOffice);
        $regionalOffice = $this->person('EMP002', $this->accraOffice);
        $district = $this->person('EMP003', $this->temaDistrict);
        $noEmployeeRecord = $this->person('EMP004', $this->headOffice, [], withEmployee: false);

        foreach ([$regionalOffice, $district, $noEmployeeRecord] as $refused) {
            $this->patchRoles($this->globalAdmin, $refused, ['admin'])->assertSessionHasErrors('roles.0');
            $this->assertFalse($refused->fresh()->hasRoles('admin'), $refused->staff_id);
        }

        $this->patchRoles($this->globalAdmin, $district, ['admin'])
            ->assertSessionHasErrors(['roles.0' => 'Global Admin can only be given to Head Office staff.']);

        $this->patchRoles($this->globalAdmin, $headOfficeStaff, ['admin'])->assertSessionHasNoErrors();
        $this->assertTrue($headOfficeStaff->fresh()->hasRoles('admin'));

        // Nobody is exempt: a super_admin is refused for a district employee too.
        $this->patchRoles($this->super, $district, ['admin'])->assertSessionHasErrors('roles.0');
        $this->assertFalse($district->fresh()->hasRoles('admin'));
    }

    public function test_creating_a_user_with_global_admin_for_a_regional_employee_is_refused_and_creates_nothing(): void
    {
        $employee = $this->makeEmployee('EMP005', $this->kumasiOffice);

        $this->actingAs($this->globalAdmin)
            ->from(route('uac.users'))
            ->post(route('uac.users.store'), ['employee_id' => $employee->id, 'roles' => $this->roleIds(['admin'])])
            ->assertSessionHasErrors('roles.0');

        $this->assertNull(User::query()->where('staff_id', 'EMP005')->first());

        $headOffice = $this->makeEmployee('EMP006', $this->headOffice);
        $this->post(route('uac.users.store'), ['employee_id' => $headOffice->id, 'roles' => $this->roleIds(['admin'])])
            ->assertSessionHasNoErrors();
        $this->assertTrue(User::query()->where('staff_id', 'EMP006')->firstOrFail()->hasRoles('admin'));
    }

    // ---------------------------------------------------------------- Transfer rule

    public function test_moving_a_global_admin_out_of_head_office_removes_the_role_audits_it_and_tells_people(): void
    {
        $otherAdmin = $this->person('GA002', $this->headOffice, ['admin']);
        $hr = $this->person('HR001', $this->headOffice, ['hr_headoffice']);
        $moved = $this->person('GA003', $this->headOffice, ['admin', 'manager']);

        $this->actingAs($hr);
        $this->moveTo($moved, $this->temaDistrict);

        $moved->refresh();
        $this->assertFalse($moved->hasRoles('admin'));
        $this->assertTrue($moved->hasRoles('manager'), 'Only the Global Admin role is removed.');

        $row = AuditLog::query()->where('action', 'head_office_roles_removed_on_transfer')->sole();
        $this->assertSame($moved->id, (int) $row->target_id);
        $this->assertSame($hr->id, $row->user_id);
        $this->assertContains('admin', $row->old_values['roles']);
        $this->assertNotContains('admin', $row->new_values['roles']);
        $this->assertSame('HeadOffice', $row->old_values['location_type']);
        $this->assertSame('Head Office', $row->old_values['district']);
        $this->assertSame('District', $row->new_values['location_type']);
        $this->assertSame('Tema District', $row->new_values['district']);
        $this->assertSame(['admin'], $row->metadata['roles']);
        $this->assertSame($hr->id, $row->metadata['actor_id']);
        $this->assertSame('Employee HR001', $row->metadata['actor_name']);
        $this->assertSame('GA003', $row->metadata['target_staff_id']);

        // Not silent: the person, whoever moved them and the other Global Admins are told.
        foreach ([$moved, $hr, $otherAdmin] as $recipient) {
            Notification::assertSentTo(
                $recipient,
                GeneralDatabaseNotification::class,
                fn ($notification) => ($notification->toArray($recipient)['type'] ?? null) === 'head_office_roles_removed'
            );
        }
    }

    public function test_the_role_is_also_removed_when_the_move_comes_from_the_staff_form(): void
    {
        $hr = $this->person('HR001', $this->headOffice, ['hr_headoffice']);
        $moved = $this->person('GA003', $this->headOffice, ['admin']);

        Livewire::actingAs($hr)
            ->test(EmployeeForm::class, ['employee' => $moved->employee])
            ->set('district_id', $this->temaDistrict->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('District', $moved->employee->fresh()->location_type);
        $this->assertFalse($moved->fresh()->hasRoles('admin'));
        $this->assertSame(1, AuditLog::query()->where('action', 'head_office_roles_removed_on_transfer')->count());
    }

    public function test_the_role_is_also_removed_when_the_move_comes_from_an_import(): void
    {
        $hr = $this->person('HR001', $this->headOffice, ['hr_headoffice']);
        $moved = $this->person('GA003', $this->headOffice, ['admin']);
        $service = app(DataImportService::class);

        $validate = new ReflectionMethod($service, 'validateRow');
        $validate->setAccessible(true);

        [$row, $errors] = $validate->invoke($service, 'employees', [
            'staff_id' => 'GA003',
            'full_name' => 'Employee GA003',
            'gender' => 'Female',
            'category' => 'Senior Staff',
            'email' => 'ga003@example.com',
            'job_title_name' => 'Officer',
            'department_name' => 'Finance',
            'district_name' => 'Obuasi District',
            'region_name' => 'Ashanti',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2020-01-06',
            'unit' => '',
            'present_appointment' => '',
        ], 2, $hr);

        $this->assertSame([], $errors);

        $this->actingAs($hr);
        $service->run('employees', [$row], $hr);

        $this->assertSame('District', $moved->employee->fresh()->location_type);
        $this->assertFalse($moved->fresh()->hasRoles('admin'));
        $this->assertSame(1, AuditLog::query()->where('action', 'head_office_roles_removed_on_transfer')->count());
    }

    public function test_the_move_and_the_role_removal_stand_or_fall_together(): void
    {
        $moved = $this->person('GA003', $this->headOffice, ['admin']);

        try {
            DB::transaction(function () use ($moved) {
                $this->moveTo($moved, $this->temaDistrict);
                $this->assertFalse($moved->fresh()->hasRoles('admin'));

                throw new RuntimeException('something later in the request failed');
            });
        } catch (RuntimeException) {
            // rolled back
        }

        $this->assertSame('HeadOffice', $moved->employee->fresh()->location_type);
        $this->assertTrue($moved->fresh()->hasRoles('admin'));
        $this->assertSame(0, AuditLog::query()->where('action', 'head_office_roles_removed_on_transfer')->count());
    }

    public function test_moves_that_do_not_leave_head_office_leave_roles_alone(): void
    {
        $stays = $this->person('GA003', $this->headOffice, ['admin']);
        $stays->employee->update(['unit' => 'Payroll']);
        $this->assertTrue($stays->fresh()->hasRoles('admin'));

        // Into Head Office, and roles that are not Head Office-only, are untouched too.
        $regional = $this->person('EMP010', $this->accraOffice, ['manager']);
        $this->moveTo($regional, $this->headOffice);
        $this->assertTrue($regional->fresh()->hasRoles('manager'));

        $manager = $this->person('EMP011', $this->headOffice, ['manager', 'secretary', 'departmental_manager']);
        $this->moveTo($manager, $this->temaDistrict);
        $this->assertEqualsCanonicalizing(['manager', 'secretary', 'departmental_manager'], $manager->fresh()->roles->pluck('name')->all());

        $this->assertSame(0, AuditLog::query()->where('action', 'head_office_roles_removed_on_transfer')->count());
    }

    public function test_every_head_office_only_role_is_removed_when_someone_moves_out_of_head_office(): void
    {
        // The removal list is the assignment rule's own list, so the two can never drift apart.
        $this->assertEqualsCanonicalizing(['admin', 'hr_headoffice', 'chief_manager'], RoleGrantPolicy::HEAD_OFFICE_ROLES);

        $otherAdmin = $this->person('GA002', $this->headOffice, ['admin']);
        $hr = $this->person('HR001', $this->headOffice, ['hr_headoffice']);
        $moved = $this->person('EMP030', $this->headOffice, ['admin', 'hr_headoffice', 'chief_manager', 'manager', 'secretary']);

        $this->actingAs($hr);
        $this->moveTo($moved, $this->temaDistrict);

        $this->assertEqualsCanonicalizing(['manager', 'secretary'], $moved->fresh()->roles->pluck('name')->all());

        $row = AuditLog::query()->where('action', 'head_office_roles_removed_on_transfer')->sole();
        $this->assertEqualsCanonicalizing(['admin', 'hr_headoffice', 'chief_manager'], $row->metadata['roles']);
        $this->assertEqualsCanonicalizing(['manager', 'secretary'], $row->new_values['roles']);
        $this->assertSame($hr->id, $row->user_id);

        // One notice per person, naming every role that went.
        Notification::assertSentTo($otherAdmin, GeneralDatabaseNotification::class, function ($notification) use ($otherAdmin) {
            $data = $notification->toArray($otherAdmin);

            return $data['type'] === 'head_office_roles_removed'
                && str_contains($data['message'], 'Global Admin')
                && str_contains($data['message'], 'Chief Manager')
                && str_contains($data['message'], 'Hr Headoffice');
        });
    }

    #[DataProvider('headOfficeOnlyRoles')]
    public function test_each_head_office_only_role_is_removed_on_its_own_and_only_comes_back_at_head_office(string $role): void
    {
        $actor = $this->person('HR001', $this->headOffice, ['hr_headoffice']);
        $holder = $this->person('EMP031', $this->headOffice, [$role]);

        $this->actingAs($actor);
        $this->moveTo($holder, $this->kumasiOffice);

        $this->assertFalse($holder->fresh()->hasRoles($role));
        $this->assertSame(1, AuditLog::query()->where('action', 'head_office_roles_removed_on_transfer')->count());

        // A Global Admin can't give it back while they are elsewhere...
        $this->patchRoles($this->globalAdmin, $holder, [$role])->assertSessionHasErrors('roles.0');
        $this->assertFalse($holder->fresh()->hasRoles($role));

        // ...and can once they are back.
        $this->moveTo($holder, $this->headOffice);
        $this->patchRoles($this->globalAdmin, $holder, [$role])->assertSessionHasNoErrors();
        $this->assertTrue($holder->fresh()->hasRoles($role));
    }

    public static function headOfficeOnlyRoles(): array
    {
        return [
            'global admin' => ['admin'],
            'head office hr' => ['hr_headoffice'],
            'chief manager' => ['chief_manager'],
        ];
    }

    public function test_the_role_cannot_be_given_back_until_they_are_at_head_office_again(): void
    {
        $otherAdmin = $this->person('GA002', $this->headOffice, ['admin']);
        $moved = $this->person('GA003', $this->headOffice, ['admin']);

        $this->actingAs($otherAdmin);
        $this->moveTo($moved, $this->temaDistrict);

        $this->patchRoles($otherAdmin, $moved, ['admin'])->assertSessionHasErrors('roles.0');
        $this->assertFalse($moved->fresh()->hasRoles('admin'));

        $this->moveTo($moved, $this->headOffice);

        $this->patchRoles($otherAdmin, $moved, ['admin'])->assertSessionHasNoErrors();
        $this->assertTrue($moved->fresh()->hasRoles('admin'));
    }

    // ---------------------------------------------------------------- What Global Admin can do

    public function test_global_admin_can_assign_any_role_but_super_admin_to_anyone_where_it_fits(): void
    {
        $regional = $this->person('EMP020', $this->kumasiOffice);
        $headOffice = $this->person('EMP021', $this->headOffice);

        // Anywhere: regional and Head Office staff, including roles ICT can't hand out.
        $this->patchRoles($this->globalAdmin, $regional, ['regional_chief_manager', 'credit_union_officer', 'managing_director', 'ict_team'])
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(
            ['regional_chief_manager', 'credit_union_officer', 'managing_director', 'ict_team'],
            $regional->fresh()->roles->pluck('name')->all()
        );

        $this->patchRoles($this->globalAdmin, $headOffice, ['hr_headoffice', 'chief_manager'])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['hr_headoffice', 'chief_manager'], $headOffice->fresh()->roles->pluck('name')->all());

        // But never super_admin.
        $this->patchRoles($this->globalAdmin, $headOffice, ['hr_headoffice', 'chief_manager', 'super_admin'])
            ->assertSessionHasErrors('roles.2');
        $this->assertFalse($headOffice->fresh()->hasRoles('super_admin'));
    }

    public function test_the_admin_permission_set_still_grants_what_it_did_before_the_rename(): void
    {
        $admin = $this->role('admin');

        $this->assertTrue($admin->permissions->pluck('name')->contains('uac.assign_roles'));
        $this->assertTrue($admin->permissions->pluck('name')->contains('leave.manage_hr_contacts'));
        $this->assertTrue($this->globalAdmin->hasPermission('uac.assign_roles'));
        $this->assertNotNull(Permission::query()->where('name', 'uac.assign_roles')->first());
    }
}
