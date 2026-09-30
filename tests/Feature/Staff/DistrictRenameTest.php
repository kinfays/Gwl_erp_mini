<?php

namespace Tests\Feature\Staff;

use App\Livewire\Staff\LocationsManager;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * An employee's location_type follows the NAME of their district and their region_id follows its region, but both were
 * only worked out when the employee was saved. Editing a district now brings its employees in line at once — and so,
 * through the same observer as any other move, takes the Head Office-only roles from anyone who is no longer at
 * Head Office.
 */
class DistrictRenameTest extends TestCase
{
    use BuildsUacOrg;
    use RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->buildUacOrg();

        $this->super = $this->person('SA001', $this->kumasiOffice, ['super_admin']);
    }

    protected function rename(District $district, string $name, ?int $regionId = null)
    {
        return Livewire::actingAs($this->super)
            ->test(LocationsManager::class)
            ->call('edit', $district->id)
            ->set('editingName', $name)
            ->set('editingRegionId', $regionId ?? $district->region_id)
            ->call('update')
            ->assertHasNoErrors();
    }

    protected function locationType(User $user): string
    {
        return Employee::query()->findOrFail($user->employee_id)->location_type;
    }

    public function test_renaming_head_office_moves_its_staff_to_a_district_and_takes_their_head_office_roles(): void
    {
        $admin = $this->person('GA001', $this->headOffice, ['admin', 'manager']);
        $hr = $this->person('HR001', $this->headOffice, ['hr_headoffice']);
        $chief = $this->person('CM001', $this->headOffice, ['chief_manager', 'secretary']);
        $regional = $this->person('EMP001', $this->accraOffice, ['manager']);

        $this->rename($this->headOffice, 'Corporate Centre');

        foreach ([$admin, $hr, $chief] as $user) {
            $this->assertSame('District', $this->locationType($user), $user->staff_id);
        }

        $this->assertEqualsCanonicalizing(['manager'], $admin->fresh()->roles->pluck('name')->all());
        $this->assertSame([], $hr->fresh()->roles->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['secretary'], $chief->fresh()->roles->pluck('name')->all());

        // One audit row per person who lost roles, made by whoever renamed the district.
        $this->assertSame(3, AuditLog::query()->where('action', 'head_office_roles_removed_on_transfer')->count());

        // The rename itself is audited with how many staff it touched; other districts are untouched.
        $row = AuditLog::query()->where('action', 'update_location')->sole();
        $this->assertSame(3, $row->metadata['employees_updated']);
        $this->assertSame('Head Office', $row->old_values['district_name']);
        $this->assertSame('Corporate Centre', $row->new_values['district_name']);
        $this->assertSame('Region', $this->locationType($regional));
        $this->assertTrue($regional->fresh()->hasRoles('manager'));
    }

    public function test_naming_a_district_head_office_or_regional_office_re_derives_its_staffs_location(): void
    {
        $inTema = $this->person('EMP002', $this->temaDistrict, ['manager']);

        $this->rename($this->temaDistrict, 'Tema Regional Office');
        $this->assertSame('Region', $this->locationType($inTema));

        $this->rename($this->temaDistrict->fresh(), 'Head Office Annex');
        $this->assertSame('HeadOffice', $this->locationType($inTema));

        // Into Head Office is never a reason to take anything away.
        $this->assertTrue($inTema->fresh()->hasRoles('manager'));
        $this->assertSame(0, AuditLog::query()->where('action', 'head_office_roles_removed_on_transfer')->count());
    }

    public function test_a_rename_that_does_not_change_anyones_location_touches_nobody(): void
    {
        $this->person('EMP003', $this->temaDistrict);
        $this->person('EMP004', $this->temaDistrict);

        $this->rename($this->temaDistrict, 'Tema East District');

        $this->assertSame(0, AuditLog::query()->where('action', 'update_location')->sole()->metadata['employees_updated']);
        $this->assertSame(0, AuditLog::query()->where('action', 'head_office_roles_removed_on_transfer')->count());
    }

    public function test_moving_a_district_to_another_region_moves_its_employees_region_too(): void
    {
        $inTema = $this->person('EMP005', $this->temaDistrict);
        $elsewhere = $this->person('EMP006', $this->obuasiDistrict);

        $this->rename($this->temaDistrict, 'Tema District', $this->ashanti->id);

        $this->assertSame($this->ashanti->id, (int) Employee::query()->findOrFail($inTema->employee_id)->region_id);
        $this->assertSame($this->ashanti->id, (int) Employee::query()->findOrFail($elsewhere->employee_id)->region_id);
        $this->assertSame($this->accra->id, (int) $this->headOffice->fresh()->region_id);
    }

    public function test_a_failing_sync_leaves_the_district_as_it_was(): void
    {
        $holder = $this->person('GA001', $this->headOffice, ['admin']);

        // If anything after the rename throws, nothing is half-applied: the name, the staff and the roles roll back.
        try {
            \Illuminate\Support\Facades\DB::transaction(function () {
                $this->headOffice->update(['district_name' => 'Corporate Centre']);
                app(\App\Services\Staff\DistrictEmployeeSync::class)->sync($this->headOffice->fresh());

                throw new \RuntimeException('later step failed');
            });
        } catch (\RuntimeException) {
            // rolled back
        }

        $this->assertSame('Head Office', $this->headOffice->fresh()->district_name);
        $this->assertSame('HeadOffice', $this->locationType($holder));
        $this->assertTrue($holder->fresh()->hasRoles('admin'));
    }
}
