<?php

namespace Tests\Feature\Uac;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Import\DataImportService;
use App\Services\Uac\RoleGrantPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * Nobody grants more than they hold: not super_admin, not a role above their own tier, not a role carrying governance
 * permissions they lack. Only a super_admin assigns or edits super_admin. The users import can't be used to get
 * around any of it.
 */
class AntiEscalationTest extends TestCase
{
    use BuildsUacOrg;
    use RefreshDatabase;

    protected User $super;

    protected User $globalAdmin;

    protected User $ict;

    protected User $target;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->buildUacOrg();

        $this->super = $this->person('SA001', $this->headOffice, ['super_admin']);
        $this->globalAdmin = $this->person('GA001', $this->headOffice, ['admin']);
        $this->ict = $this->person('ICT001', $this->headOffice, ['ict_team']);
        $this->target = $this->person('EMP001', $this->headOffice);
    }

    protected function customRole(string $name, array $permissions, bool $ictAssignable = true): Role
    {
        $role = Role::query()->create([
            'name' => $name,
            'display_name' => str($name)->replace('_', ' ')->title()->toString(),
            'is_system' => false,
            'ict_assignable' => $ictAssignable,
        ]);

        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return $role;
    }

    // ---------------------------------------------------------------- Permissions you hold

    public function test_the_ict_team_cannot_grant_a_role_carrying_governance_permissions_it_lacks(): void
    {
        $this->assertFalse($this->ict->hasPermission('uac.import_data'));
        $this->assertTrue($this->ict->hasPermission('uac.assign_roles'));

        $this->customRole('uac_importer', ['uac.import_data']);
        $this->customRole('uac_assigner', ['uac.assign_roles']);
        // Operational permissions the ICT team lacks don't count: it hands out HR and letters roles by design.
        $this->customRole('letters_reader', ['letters.view']);

        $this->patchRoles($this->ict, $this->target, ['uac_importer'])
            ->assertSessionHasErrors(['roles.0' => 'You cannot grant Uac Importer: it carries permissions you do not hold yourself (uac.import_data).']);
        $this->assertFalse($this->target->fresh()->hasRoles('uac_importer'));

        $this->patchRoles($this->ict, $this->target, ['uac_assigner', 'letters_reader'])->assertSessionHasNoErrors();
        $this->assertTrue($this->target->fresh()->hasRoles('uac_assigner', 'letters_reader'));
    }

    public function test_a_global_admin_cannot_grant_a_role_with_a_governance_permission_they_do_not_hold(): void
    {
        $this->assertFalse($this->globalAdmin->hasPermission('uac.view_audit_log'));

        $this->customRole('audit_reader', ['uac.view_audit_log']);
        $this->customRole('importer', ['uac.import_data']);

        $this->patchRoles($this->globalAdmin, $this->target, ['audit_reader'])->assertSessionHasErrors('roles.0');
        $this->assertFalse($this->target->fresh()->hasRoles('audit_reader'));

        $this->patchRoles($this->globalAdmin, $this->target, ['importer'])->assertSessionHasNoErrors();
        $this->assertTrue($this->target->fresh()->hasRoles('importer'));

        // super_admin holds nothing back.
        $this->patchRoles($this->super, $this->target, ['importer', 'audit_reader'])->assertSessionHasNoErrors();
        $this->assertTrue($this->target->fresh()->hasRoles('audit_reader'));
    }

    // ---------------------------------------------------------------- Tiers

    public function test_nobody_grants_a_role_above_their_own_tier_whatever_the_flags_say(): void
    {
        // Even if someone flags the tiered roles as ICT-assignable, the tier rule still stops it.
        Role::query()->whereIn('name', ['admin', 'ict_team'])->update(['ict_assignable' => true]);

        $this->patchRoles($this->ict, $this->target, ['admin'])->assertSessionHasErrors('roles.0');
        $this->assertFalse($this->target->fresh()->hasRoles('admin'));

        $policy = app(RoleGrantPolicy::class);
        $this->assertSame(User::TIER_SUPER_ADMIN, $this->super->tier());
        $this->assertSame(User::TIER_GLOBAL_ADMIN, $this->globalAdmin->tier());
        $this->assertSame(User::TIER_ICT, $this->ict->tier());
        $this->assertSame(User::TIER_STAFF, $this->target->tier());
        $this->assertSame(User::TIER_GLOBAL_ADMIN, $policy->roleTier('admin'));

        // The flag can't be switched on for those roles from the UI either.
        $this->assertFalse($policy->canSetIctAssignable($this->super, $this->role('admin')));
        $this->assertFalse($policy->canSetIctAssignable($this->super, $this->role('ict_team')));
        $this->assertTrue($policy->canSetIctAssignable($this->globalAdmin, $this->role('hr_region')));

        // A user with no UAC standing at all assigns nothing.
        $hr = $this->person('HR001', $this->headOffice, ['hr_headoffice']);
        $this->assertSame([], $policy->assignableRolesFor($hr, $this->target)->all());
    }

    // ---------------------------------------------------------------- super_admin

    public function test_only_a_super_admin_can_assign_or_remove_the_super_admin_role(): void
    {
        foreach ([$this->globalAdmin, $this->ict] as $actor) {
            $this->patchRoles($actor, $this->target, ['super_admin'])
                ->assertSessionHasErrors(['roles.0' => 'Only a super admin can assign the Super Admin role.']);
        }
        $this->assertFalse($this->target->fresh()->hasRoles('super_admin'));

        $this->patchRoles($this->super, $this->target, ['super_admin'])->assertSessionHasNoErrors();
        $this->assertTrue($this->target->fresh()->hasRoles('super_admin'));

        // Now a super_admin account: invisible to and untouchable by anyone else, removable only by a super_admin.
        $policy = app(RoleGrantPolicy::class);
        $this->assertFalse($policy->canRemoveRole($this->globalAdmin, $this->role('super_admin'), $this->target));
        $this->patchRoles($this->globalAdmin, $this->target, [])->assertNotFound();
        $this->assertTrue($this->target->fresh()->hasRoles('super_admin'));

        $this->patchRoles($this->super, $this->target, [])->assertSessionHasNoErrors();
        $this->assertFalse($this->target->fresh()->hasRoles('super_admin'));
    }

    public function test_the_super_admin_role_and_definition_cannot_be_edited_by_a_global_admin(): void
    {
        $policy = app(RoleGrantPolicy::class);

        $this->assertFalse($policy->canEditRolePermissions($this->globalAdmin, $this->role('super_admin')));
        $this->assertFalse($policy->canEditRolePermissions($this->super, $this->role('super_admin')));
        $this->assertFalse($policy->canEditRolePermissions($this->globalAdmin, $this->role('admin')));
        $this->assertTrue($policy->canEditRolePermissions($this->super, $this->role('admin')));
    }

    // ---------------------------------------------------------------- Forged requests

    public function test_forged_role_ids_are_refused(): void
    {
        $missing = $this->actingAs($this->globalAdmin)->from(route('uac.users'))
            ->patch(route('uac.users.update', $this->target), ['roles' => [999999]]);
        $missing->assertSessionHasErrors(['roles.0' => 'That role does not exist.']);

        // The implicit Employee role is never assigned through the screen.
        $this->actingAs($this->globalAdmin)->from(route('uac.users'))
            ->patch(route('uac.users.update', $this->target), ['roles' => [$this->role('employee')->id]])
            ->assertSessionHasErrors('roles.0');
        $this->assertFalse($this->target->fresh()->hasRoles('employee'));
    }

    // ---------------------------------------------------------------- Users import

    public function test_the_users_import_is_a_global_admin_job_whichever_screen_it_comes_from(): void
    {
        $hr = $this->person('HR001', $this->headOffice, ['hr_headoffice']);
        $csv = "staff_id,email,role_slugs,is_active\nEMP001,emp001@example.com,admin,1\n";

        // HR reaches the staff import screen; a crafted "users" upload must not turn into role assignment.
        $this->actingAs($hr)
            ->post(route('staff.import.preview'), ['type' => 'users', 'file' => UploadedFile::fake()->createWithContent('users.csv', $csv)])
            ->assertForbidden();

        // The ICT team can't reach the UAC import at all.
        $this->actingAs($this->ict)
            ->post(route('uac.import.preview'), ['type' => 'users', 'file' => UploadedFile::fake()->createWithContent('users.csv', $csv)])
            ->assertForbidden();

        // And the service refuses on its own.
        $service = app(DataImportService::class);
        $row = ['staff_id' => 'EMP001', 'email' => 'emp001@example.com', 'role_slugs' => ['secretary'], 'is_active' => true];
        $this->assertThrows(fn () => $service->run('users', [$row], $hr), HttpException::class);
        $this->assertThrows(fn () => $service->run('users', [$row], $this->ict), HttpException::class);
        $this->assertFalse($this->target->fresh()->hasRoles('secretary'));
    }

    public function test_the_users_import_applies_the_same_rules_as_the_users_screen(): void
    {
        $regional = $this->person('EMP002', $this->kumasiOffice);
        $service = app(DataImportService::class);

        $validate = new ReflectionMethod($service, 'validateRow');
        $validate->setAccessible(true);
        $check = fn (string $staffId, string $roles, ?User $actor = null) => $validate->invoke(
            $service,
            'users',
            ['staff_id' => $staffId, 'email' => strtolower($staffId).'@example.com', 'role_slugs' => $roles, 'is_active' => '1'],
            2,
            $actor ?? $this->globalAdmin
        );

        // Global Admin to a regional employee, super_admin, a role above the tier: all refused with a reason.
        [, $errors] = $check('EMP002', 'admin');
        $this->assertStringContainsString('Head Office staff', $errors[0]['message']);
        [, $errors] = $check('EMP001', 'super_admin');
        $this->assertStringContainsString('Only a super admin', $errors[0]['message']);
        [, $errors] = $check('EMP001', 'hr_region');
        $this->assertStringContainsString('not Head Office staff', $errors[0]['message']);

        // Roles that fit are accepted, and imported through the same service (audited, diffed).
        [$row, $errors] = $check('EMP002', 'hr_region');
        $this->assertSame([], $errors);

        $this->actingAs($this->globalAdmin);
        $service->run('users', [$row], $this->globalAdmin);

        $this->assertTrue($regional->fresh()->hasRoles('hr_region'));
        $this->assertSame(1, AuditLog::query()->where('action', 'role_assigned')->count());

        // Outside a scoped actor's scope, the employee doesn't exist for them.
        [, $errors] = $check('EMP002', 'manager', $this->ict);
        $this->assertStringContainsString('outside your scope', $errors[0]['message']);
    }
}
