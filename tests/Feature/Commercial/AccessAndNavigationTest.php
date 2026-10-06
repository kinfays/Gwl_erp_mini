<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\BatchShow;
use App\Livewire\Commercial\Batches;
use App\Models\CommercialImportBatch;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ErpNavigation;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

class AccessAndNavigationTest extends CommercialTestCase
{
    // ---------------------------------------------------------------- region scope

    public function test_a_regional_user_cannot_see_open_or_void_another_regions_batch(): void
    {
        $other = $this->batchIn($this->ashanti, 'ashanti-report.xlsx');
        $own = $this->batchIn($this->accraWest, 'accra-report.xlsx');
        $officer = $this->officer();

        Livewire::actingAs($officer)->test(Batches::class)
            ->assertSee('accra-report.xlsx')
            ->assertDontSee('ashanti-report.xlsx');

        $this->actingAs($officer)->get(route('commercial.batches.show', $other))->assertForbidden();
        $this->actingAs($officer)->get(route('commercial.batches.show', $own))->assertOk();

        Livewire::actingAs($officer)->test(BatchShow::class, ['batch' => $other])->assertForbidden();

        // A call replayed against a batch that is no longer in the user's region is refused too.
        $component = Livewire::actingAs($officer)->test(BatchShow::class, ['batch' => $own]);
        $own->update(['region_id' => $this->ashanti->id]);

        $component->set('voidReason', 'Not mine to void')->call('voidBatch')->assertForbidden();
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $own->fresh()->status);
    }

    public function test_head_office_staff_global_admin_and_super_admin_see_every_region(): void
    {
        $this->batchIn($this->ashanti, 'ashanti-report.xlsx');
        $this->batchIn($this->accraWest, 'accra-report.xlsx');

        $headOffice = $this->officer('900010', $this->accraWest, $this->headOffice);
        $this->assertSame('HeadOffice', $headOffice->employee->location_type);

        foreach ([$headOffice, $this->superAdmin(), $this->userWithRoles('900011', ['admin', 'commercial_officer'], $this->ashanti)] as $user) {
            Livewire::actingAs($user)->test(Batches::class)
                ->assertSee('accra-report.xlsx')
                ->assertSee('ashanti-report.xlsx');
        }
    }

    public function test_a_user_with_no_region_sees_no_batches(): void
    {
        $this->batchIn($this->accraWest, 'accra-report.xlsx');

        $user = $this->userWithoutEmployee('900012', ['commercial_officer']);

        Livewire::actingAs($user)->test(Batches::class)->assertDontSee('accra-report.xlsx');
    }

    public function test_a_regional_officer_cannot_upload_a_report_for_another_region(): void
    {
        $this->actingAs($this->officer());

        $preview = $this->previewOf($this->readingFile(['region' => 'ASHANTI']));

        $this->assertTrue($preview['blocked']);
        $this->assertStringContainsString('you can only upload reports for your own region', collect($preview['errors'])->pluck('message')->implode(' '));
        $this->assertSame(0, CommercialImportBatch::query()->count());
    }

    public function test_an_officer_without_a_region_cannot_upload_at_all(): void
    {
        $user = $this->userWithoutEmployee('900013', ['commercial_officer']);

        Livewire::actingAs($user)->test(Batches::class)
            ->call('openUpload')
            ->set('file', $this->readingFile())
            ->call('previewFile')
            ->assertHasErrors('file');
    }

    // ---------------------------------------------------------------- permissions

    public function test_users_without_the_upload_permission_get_403_on_upload(): void
    {
        // Can work with batches (resolve matches) but may not upload.
        $resolver = $this->userWithRoles('900020', []);
        $role = Role::query()->create(['name' => 'commercial_resolver_test', 'display_name' => 'Resolver', 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->where('name', 'commercial.resolve_matches')->value('id'));
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL, 'can_access' => true]);
        $resolver->roles()->attach($role);
        $resolver = $resolver->fresh();

        Livewire::actingAs($resolver)->test(Batches::class)->assertOk()->call('openUpload')->assertForbidden();

        Livewire::actingAs($resolver)->test(Batches::class)
            ->set('file', $this->readingFile())
            ->call('previewFile')
            ->assertForbidden();

        Livewire::actingAs($resolver)->test(Batches::class)->call('runImport')->assertForbidden();

        $this->assertSame(0, CommercialImportBatch::query()->count());
    }

    public function test_the_manager_role_can_view_but_not_reach_the_upload_screens(): void
    {
        $manager = $this->userWithRoles('900021', ['commercial_manager']);

        $this->actingAs($manager)->get(route('commercial.home'))->assertOk();
        $this->actingAs($manager)->get(route('commercial.batches'))->assertForbidden();
        Livewire::actingAs($manager)->test(Batches::class)->assertForbidden();
    }

    public function test_management_roles_get_the_overview_only_and_employees_nothing(): void
    {
        $chief = $this->userWithRoles('900022', ['regional_chief_manager']);
        $this->actingAs($chief)->get(route('commercial.home'))->assertOk();
        $this->actingAs($chief)->get(route('commercial.batches'))->assertForbidden();
        $this->assertFalse($chief->hasPermission('commercial.view_reader_performance'));

        $employee = $this->userWithRoles('900023', ['employee']);
        // The module middleware turns an employee away to the dashboard, as for every other module.
        $this->actingAs($employee)->get(route('commercial.home'))->assertRedirect('/dashboard');
    }

    public function test_the_officer_can_open_the_screens_and_void_but_not_change_settings(): void
    {
        $officer = $this->officer();

        $this->actingAs($officer)->get(route('commercial.home'))->assertOk()->assertSee('Commercial');
        $this->actingAs($officer)->get(route('commercial.batches'))->assertOk()->assertSee('Report Uploads');

        $this->assertTrue($officer->hasPermission('commercial.void_batches'));
        $this->assertFalse($officer->hasPermission('commercial.manage_settings'));
        $this->assertTrue($this->superAdmin()->hasPermission('commercial.manage_settings'));
    }

    // ---------------------------------------------------------------- navigation and the feature flag

    public function test_the_module_is_in_the_navigation_when_the_flag_is_on_and_hidden_when_it_is_off(): void
    {
        $navigation = app(ErpNavigation::class);
        $officer = $this->officer();
        $employee = $this->userWithRoles('900030', ['employee']);

        $titles = fn ($user) => collect($navigation->build($user, 'leave')['modules'])->pluck('title')->all();

        $superAdmin = $this->superAdmin();

        $this->assertContains('Commercial', $titles($officer));
        $this->assertContains('Commercial', $titles($superAdmin));
        $this->assertNotContains('Commercial', $titles($employee));

        $labels = collect($navigation->build($officer, 'commercial')['sidebar'])->pluck('label')->all();
        $this->assertSame(['Overview', 'Uploads'], $labels);

        $managerLabels = collect($navigation->build($this->userWithRoles('900031', ['commercial_manager']), 'commercial')['sidebar'])->pluck('label')->all();
        $this->assertSame(['Overview'], $managerLabels);

        config(['gwl.commercial_module_enabled' => false]);

        $this->assertNotContains('Commercial', $titles($officer));
        $this->assertNotContains('Commercial', $titles($superAdmin));
    }

    public function test_with_the_flag_off_the_routes_do_not_exist(): void
    {
        $this->assertTrue(Route::has('commercial.home'), 'sanity: registered while the flag is on');

        $this->rebuildApplicationWithFlag('false');

        try {
            $this->assertFalse(config('gwl.commercial_module_enabled'));

            foreach (['commercial.home', 'commercial.batches', 'commercial.batches.show'] as $name) {
                $this->assertFalse(Route::has($name), "{$name} must not be registered when GWL_COMMERCIAL_MODULE_ENABLED is false");
            }

            $this->get('/commercial')->assertNotFound();
        } finally {
            $this->restoreFlag();
        }
    }

    public function test_the_flag_defaults_to_off_and_every_setting_is_documented(): void
    {
        $this->assertTrue(config('gwl.commercial_module_enabled'), 'phpunit.xml switches it on so the suite always runs');

        $defaults = require base_path('config/gwl.php');
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('GWL_COMMERCIAL_MODULE_ENABLED=false', $envExample);

        foreach ([
            'GWL_COMMERCIAL_MODULE_ENABLED' => 'commercial_module_enabled',
            'GWL_COMMERCIAL_IMPORT_MAX_MB' => 'commercial_import_max_mb',
            'GWL_COMMERCIAL_MIN_VISITS_FOR_OUTLIER' => 'commercial_min_visits_for_outlier',
            'GWL_COMMERCIAL_OUTLIER_ZSCORE' => 'commercial_outlier_zscore',
            'GWL_COMMERCIAL_WORKLOAD_LOW_PCT' => 'commercial_workload_low_pct',
            'GWL_COMMERCIAL_WORKLOAD_HIGH_PCT' => 'commercial_workload_high_pct',
            'GWL_COMMERCIAL_TARGET_SKIP_RATE_PCT' => 'commercial_target_skip_rate_pct',
            'GWL_COMMERCIAL_TARGET_COVERAGE_PCT' => 'commercial_target_coverage_pct',
            'GWL_COMMERCIAL_TARGET_COLLECTION_PCT' => 'commercial_target_collection_pct',
        ] as $variable => $key) {
            $this->assertStringContainsString($variable.'=', $envExample, "{$variable} is missing from .env.example");
            $this->assertArrayHasKey($key, $defaults, "gwl.{$key} is missing");
        }
    }

    // ---------------------------------------------------------------- helpers

    protected function batchIn($region, string $filename): CommercialImportBatch
    {
        return CommercialImportBatch::query()->create([
            'report_type' => CommercialImportBatch::TYPE_READING_SUMMARY,
            'region_id' => $region->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'granularity' => CommercialImportBatch::GRANULARITY_MONTHLY,
            'source_filename' => $filename,
            'file_hash' => hash('sha256', $filename),
            'status' => CommercialImportBatch::STATUS_IMPORTED,
            'imported_at' => now(),
        ]);
    }

    /** Boots a fresh application whose GWL_COMMERCIAL_MODULE_ENABLED is $value, so routes register (or not) at boot. */
    private function rebuildApplicationWithFlag(string $value): void
    {
        $_ENV['GWL_COMMERCIAL_MODULE_ENABLED'] = $value;
        $_SERVER['GWL_COMMERCIAL_MODULE_ENABLED'] = $value;
        putenv('GWL_COMMERCIAL_MODULE_ENABLED='.$value);

        $this->refreshApplication();
    }

    private function restoreFlag(): void
    {
        $_ENV['GWL_COMMERCIAL_MODULE_ENABLED'] = 'true';
        $_SERVER['GWL_COMMERCIAL_MODULE_ENABLED'] = 'true';
        putenv('GWL_COMMERCIAL_MODULE_ENABLED=true');
    }
}
