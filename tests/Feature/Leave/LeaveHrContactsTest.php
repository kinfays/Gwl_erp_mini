<?php

namespace Tests\Feature\Leave;

use App\Livewire\Leave\HrContacts;
use App\Models\Employee;
use App\Models\LeaveHrContact;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Leave\LeaveHrContactService;
use Database\Seeders\LeaveApprovalRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Leave\Concerns\BuildsLeaveOrg;
use Tests\TestCase;

/**
 * The HR contacts screen: who may open it, who may edit which scope (Head Office and each region), that a scope
 * has one contact, and that every change is audited.
 */
class LeaveHrContactsTest extends TestCase
{
    use BuildsLeaveOrg;
    use RefreshDatabase;

    protected Employee $headOfficeHr;

    protected Employee $accraHr;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            LeaveApprovalRolePermissionSeeder::class,
        ]);

        $this->buildOrg();

        $this->headOfficeHr = $this->staff('HRH201', $this->headOffice, $this->finance, ['hr_headoffice']);
        $this->accraHr = $this->staff('HRA201', $this->accraOffice, $this->finance, ['hr_region']);
    }

    public function test_the_seeders_grant_the_managing_director_and_hr_roles_what_they_need(): void
    {
        $granted = fn (string $role) => Role::query()->where('name', $role)->firstOrFail()
            ->permissions()->pluck('name')->all();

        foreach (['super_admin', 'admin', 'hr_headoffice', 'hr_region'] as $role) {
            $this->assertContains('leave.manage_hr_contacts', $granted($role), $role);
        }

        $this->assertContains('leave.approve_final', $granted('managing_director'));
        $this->assertTrue((bool) DB::table('module_access')
            ->where('role_id', Role::query()->where('name', 'managing_director')->value('id'))
            ->where('module', 'leave')
            ->value('can_access'));
    }

    public function test_head_office_hr_regional_hr_and_admins_can_open_the_screen_others_cannot(): void
    {
        $admin = $this->staff('ADM201', $this->headOffice, $this->finance, ['admin']);
        $superAdmin = $this->staff('SA201', $this->headOffice, $this->finance, ['super_admin']);
        $manager = $this->staff('DM201', $this->temaDistrict, $this->operations, ['district_manager']);
        $employee = $this->staff('EMP201', $this->temaDistrict, $this->operations);

        foreach ([$this->headOfficeHr, $this->accraHr, $admin, $superAdmin] as $allowed) {
            $this->actingAs($this->userOf($allowed))->get(route('leave.hr-contacts'))->assertOk();
        }

        foreach ([$manager, $employee] as $denied) {
            $this->actingAs($this->userOf($denied))->get(route('leave.hr-contacts'))->assertForbidden();
        }
    }

    public function test_a_role_outside_the_four_cannot_manage_contacts_even_with_the_permission(): void
    {
        $custom = Role::query()->create(['name' => 'custom_hr', 'display_name' => 'Custom HR']);
        $custom->permissions()->attach(Permission::query()->where('name', 'leave.manage_hr_contacts')->first());
        $employee = $this->staff('EMP202', $this->accraOffice, $this->finance, ['custom_hr']);

        Livewire::actingAs($this->userOf($employee))->test(HrContacts::class)->assertForbidden();
    }

    public function test_head_office_hr_saves_the_head_office_and_region_contacts_and_each_save_is_audited(): void
    {
        $screen = Livewire::actingAs($this->userOf($this->headOfficeHr))->test(HrContacts::class)
            ->assertSee('Head Office')
            ->assertSee('Greater Accra')
            ->assertSee('Ashanti');

        $screen->call('edit', HrContacts::HEAD_OFFICE)
            ->set('email', 'hr.ho@example.com')
            ->set('name', 'HO HR Desk')
            ->call('save')
            ->assertHasNoErrors();

        $screen->call('edit', (string) $this->ashanti->id)
            ->set('email', 'hr.ashanti@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $headOffice = LeaveHrContact::query()->whereNull('region_id')->sole();
        $this->assertSame('hr.ho@example.com', $headOffice->email);
        $this->assertSame('HO HR Desk', $headOffice->name);
        $this->assertTrue($headOffice->is_active);
        $this->assertSame('hr.ashanti@example.com', LeaveHrContact::query()->where('region_id', $this->ashanti->id)->value('email'));

        $log = DB::table('audit_logs')->where('action', 'leave_hr_contact_create')->where('target_id', $headOffice->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('leave', $log->module);
        $this->assertSame($this->userOf($this->headOfficeHr)->id, (int) $log->user_id);
    }

    public function test_saving_a_scope_again_updates_its_single_contact(): void
    {
        $screen = Livewire::actingAs($this->userOf($this->headOfficeHr))->test(HrContacts::class);

        foreach (['first@example.com', 'second@example.com'] as $email) {
            $screen->call('edit', HrContacts::HEAD_OFFICE)->set('email', $email)->call('save');
        }

        // NULL region_id isn't covered by the unique index, so the service keeps Head Office to one row.
        $this->assertSame(1, LeaveHrContact::query()->whereNull('region_id')->count());
        $this->assertSame('second@example.com', LeaveHrContact::query()->whereNull('region_id')->value('email'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'leave_hr_contact_create')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'leave_hr_contact_update')->count());

        $update = DB::table('audit_logs')->where('action', 'leave_hr_contact_update')->first();
        $this->assertSame('first@example.com', json_decode($update->metadata, true)['before']['email']);
        $this->assertSame('second@example.com', json_decode($update->metadata, true)['after']['email']);
    }

    public function test_a_contact_can_be_deactivated_and_removed(): void
    {
        $contact = LeaveHrContact::query()->create(['region_id' => $this->accra->id, 'email' => 'hr.accra@example.com']);
        $screen = Livewire::actingAs($this->userOf($this->headOfficeHr))->test(HrContacts::class);

        $screen->call('edit', (string) $this->accra->id)->set('isActive', false)->call('save')->assertHasNoErrors();
        $this->assertFalse($contact->fresh()->is_active);

        $screen->call('delete', $contact->id);
        $this->assertNull(LeaveHrContact::query()->find($contact->id));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'leave_hr_contact_delete')->where('target_id', $contact->id)->exists());
    }

    public function test_regional_hr_manage_only_their_own_region(): void
    {
        $ashantiContact = LeaveHrContact::query()->create(['region_id' => $this->ashanti->id, 'email' => 'hr.ashanti@example.com']);
        $headOfficeContact = LeaveHrContact::query()->create(['region_id' => null, 'email' => 'hr.ho@example.com']);

        $screen = Livewire::actingAs($this->userOf($this->accraHr))->test(HrContacts::class)
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('scope')->all() === [(string) $this->accra->id])
            ->assertDontSee('Ashanti');

        // Their own region: allowed (the screen even opens on it).
        $screen->set('email', 'hr.accra@example.com')->call('save')->assertHasNoErrors();
        $this->assertSame('hr.accra@example.com', LeaveHrContact::query()->where('region_id', $this->accra->id)->value('email'));

        // Anyone else's, and Head Office: refused.
        $screen->call('edit', (string) $this->ashanti->id)->assertForbidden();
        Livewire::actingAs($this->userOf($this->accraHr))->test(HrContacts::class)->call('edit', HrContacts::HEAD_OFFICE)->assertForbidden();
        Livewire::actingAs($this->userOf($this->accraHr))->test(HrContacts::class)->call('delete', $ashantiContact->id)->assertForbidden();
        Livewire::actingAs($this->userOf($this->accraHr))->test(HrContacts::class)->call('delete', $headOfficeContact->id)->assertForbidden();

        $this->assertNotNull($ashantiContact->fresh());
        $this->assertNotNull($headOfficeContact->fresh());
    }

    public function test_a_forged_scope_cannot_get_past_the_service(): void
    {
        $service = app(LeaveHrContactService::class);
        $regionalHr = $this->userOf($this->accraHr);

        foreach ([null, $this->ashanti->id] as $regionId) {
            $this->assertThrows(
                fn () => $service->save($regionalHr, $regionId, ['email' => 'x@example.com']),
                AuthorizationException::class
            );
        }

        $this->assertSame(0, LeaveHrContact::query()->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'like', 'leave_hr_contact_%')->count());
    }

    public function test_the_email_is_validated(): void
    {
        Livewire::actingAs($this->userOf($this->headOfficeHr))->test(HrContacts::class)
            ->call('edit', HrContacts::HEAD_OFFICE)
            ->set('email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['email' => 'email']);

        $this->assertSame(0, LeaveHrContact::query()->count());
    }

    public function test_an_unknown_scope_is_not_found(): void
    {
        Livewire::actingAs($this->userOf($this->headOfficeHr))->test(HrContacts::class)
            ->call('edit', '99999')
            ->assertNotFound();
    }
}
