<?php

namespace Tests\Feature\Staff;

use App\Models\Department;
use App\Models\District;
use App\Models\JobTitle;
use App\Models\Region;
use App\Services\Import\DataImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_import_normalizes_numeric_staff_id_and_uses_existing_references(): void
    {
        Notification::fake();
        $this->createReferenceData();

        [$normalized, $errors] = $this->validateEmployeeRow([
            'staff_id' => 123456,
        ]);

        $this->assertSame([], $errors);
        $this->assertSame('123456', $normalized['staff_id']);

        app(DataImportService::class)->run('employees', [$normalized]);

        $this->assertDatabaseHas('employees', [
            'staff_id' => '123456',
            'full_name' => 'Frank Bin Frank',
        ]);
        $this->assertDatabaseCount('job_titles', 1);
        $this->assertDatabaseCount('departments', 1);
        $this->assertDatabaseCount('districts', 1);
        $this->assertDatabaseCount('regions', 1);
    }

    public function test_employee_import_reports_missing_reference_data(): void
    {
        [, $errors] = $this->validateEmployeeRow();

        $messages = collect($errors)->pluck('message')->all();

        $this->assertContains('The job title must already exist in the system.', $messages);
        $this->assertContains('The department must already exist in the system.', $messages);
        $this->assertContains('The district must already exist in the system.', $messages);
        $this->assertContains('The region must already exist in the system.', $messages);
    }

    protected function validateEmployeeRow(array $overrides = []): array
    {
        $service = app(DataImportService::class);
        $method = new ReflectionMethod($service, 'validateRow');
        $method->setAccessible(true);

        return $method->invoke($service, 'employees', array_merge($this->employeeRow(), $overrides), 2);
    }

    protected function employeeRow(): array
    {
        return [
            'staff_id' => '123456',
            'full_name' => 'Frank Bin Frank',
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => 'fbfra@test.com',
            'job_title_name' => 'HR Officer',
            'department_name' => 'Administration',
            'district_name' => 'Accra West Regional Office',
            'region_name' => 'Greater Accra',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-05-04',
            'unit' => 'HR Operations',
            'present_appointment' => '2024-01-15',
        ];
    }

    protected function createReferenceData(): void
    {
        $region = Region::query()->create(['region_name' => 'Greater Accra']);

        District::query()->create([
            'district_name' => 'Accra West Regional Office',
            'region_id' => $region->id,
        ]);

        Department::query()->create(['department_name' => 'Administration']);
        JobTitle::query()->create(['job_title_name' => 'HR Officer']);
    }
}
