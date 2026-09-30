<?php

namespace Tests\Feature\Letters\Concerns;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\MailLetter;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Letters\LetterWorkflowService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * A small organisation for the Letters tests: one region (Greater Accra) with Head Office (a district, as in the
 * app) and a regional office, plus one department. Call buildLettersOrg() from setUp(), then create people with
 * letterStaff().
 *
 * Roles are created with the same permissions LettersRolePermissionSeeder grants and module access to `letters`,
 * so the Livewire permission checks (letters.create / forward / remark) and enforceLivewireModule('letters') run
 * for real instead of being bypassed by super_admin.
 */
trait BuildsLettersOrg
{
    protected Region $accra;

    protected District $headOffice;

    protected District $accraOffice;

    protected Department $registry;

    protected Department $finance;

    /** A second region (its regional office and a district) and a district in Greater Accra, for the office-scoping tests. */
    protected Region $north;

    protected District $northOffice;

    protected District $northDistrict;

    protected District $temaDistrict;

    /** @var array<string, list<string>> role => permission slugs (mirrors LettersRolePermissionSeeder) */
    protected array $lettersRoleMap = [
        'secretary' => ['letters.view', 'letters.create', 'letters.forward', 'letters.remark', 'letters.close', 'letters.export'],
        'manager' => ['letters.view', 'letters.forward', 'letters.remark'],
        'departmental_manager' => ['letters.view', 'letters.forward', 'letters.remark'],
        'district_manager' => ['letters.view', 'letters.forward', 'letters.remark'],
        'chief_manager' => ['letters.view', 'letters.forward', 'letters.remark', 'letters.close'],
        'regional_chief_manager' => ['letters.view', 'letters.forward', 'letters.remark', 'letters.close'],
        'letters_viewer' => ['letters.view'],
    ];

    protected function buildLettersOrg(): void
    {
        $this->accra = Region::query()->create(['region_name' => 'Greater Accra']);
        $this->headOffice = District::query()->create(['district_name' => 'Head Office', 'region_id' => $this->accra->id]);
        $this->accraOffice = District::query()->create(['district_name' => 'Accra Regional Office', 'region_id' => $this->accra->id]);
        $this->registry = Department::query()->create(['department_name' => 'Registry']);
        $this->finance = Department::query()->create(['department_name' => 'Finance']);
        $this->temaDistrict = District::query()->create(['district_name' => 'Tema District', 'region_id' => $this->accra->id]);
        $this->north = Region::query()->create(['region_name' => 'Northern Zone']);
        $this->northOffice = District::query()->create(['district_name' => 'Tamale Regional Office', 'region_id' => $this->north->id]);
        $this->northDistrict = District::query()->create(['district_name' => 'Yendi District', 'region_id' => $this->north->id]);

        foreach ($this->lettersRoleMap as $roleName => $slugs) {
            $role = Role::query()->firstOrCreate(
                ['name' => $roleName],
                ['display_name' => str($roleName)->replace('_', ' ')->title()->toString(), 'is_system' => true]
            );

            ModuleAccess::query()->updateOrCreate(
                ['role_id' => $role->id, 'module' => Permission::MODULE_LETTERS],
                ['can_access' => true]
            );

            $permissionIds = collect($slugs)->map(fn (string $slug) => Permission::query()->firstOrCreate(
                ['name' => $slug],
                ['display_name' => $slug, 'module' => Permission::MODULE_LETTERS]
            )->id);

            $role->permissions()->syncWithoutDetaching($permissionIds->all());
        }
    }

    /**
     * An employee with a linked user holding $roles (default: secretary). The model events are off so no invite
     * email is sent.
     *
     * @param  list<string>|null  $roles
     */
    protected function letterStaff(
        string $staffId,
        ?District $district = null,
        ?array $roles = null,
        bool $employeeActive = true,
        ?Department $department = null,
    ): Employee {
        $district ??= $this->headOffice;
        $roles ??= ['secretary'];

        $employee = Employee::withoutEvents(fn () => Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'Employee '.$staffId,
            'gender' => 'Female',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer'])->id,
            'department_id' => ($department ?? $this->registry)->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'location_type' => Employee::locationTypeFor($district->district_name),
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2020-01-06',
            'is_active' => $employeeActive,
        ]));

        $user = User::query()->create([
            'staff_id' => $employee->staff_id,
            'employee_id' => $employee->id,
            'full_name' => $employee->full_name,
            'email' => $employee->email,
            'password' => Hash::make('abc12'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        foreach ($roles as $role) {
            $user->roles()->attach(Role::query()->firstOrCreate(
                ['name' => $role],
                ['display_name' => str($role)->replace('_', ' ')->title()->toString(), 'is_system' => true]
            ));
        }

        return $employee;
    }

    protected function letterUserOf(Employee $employee): User
    {
        return User::query()->where('employee_id', $employee->id)->firstOrFail();
    }

    protected function lettersWorkflow(): LetterWorkflowService
    {
        return app(LetterWorkflowService::class);
    }

    protected function letterData(array $overrides = []): array
    {
        return [
            'subject' => 'Request for a laptop',
            'ref_no' => 'REF/001',
            'type' => 'External',
            'company_sender' => 'Acme Supplies Ltd',
            'date_on_letter' => '2026-09-01',
            'region_id' => $this->accra->id,
            ...$overrides,
        ];
    }

    protected function createLetter(Employee $creator, array $overrides = []): MailLetter
    {
        return $this->lettersWorkflow()->create($creator, $this->letterData($overrides));
    }

    /** @return Collection<int, MailLetter> $count letters created (and so held) by $creator, subjects "{$prefix} 1".. */
    protected function createLetters(Employee $creator, int $count, string $prefix = 'Letter'): Collection
    {
        return collect(range(1, $count))->map(fn (int $i) => $this->createLetter($creator, ['subject' => "{$prefix} {$i}"]));
    }
}
