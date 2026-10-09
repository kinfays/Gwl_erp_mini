<?php

namespace Tests\Feature\Commercial\Customers;

use App\Livewire\Commercial\Customers\Lookups;
use App\Livewire\Commercial\Customers\Uploads;
use App\Models\AuditLog;
use App\Models\CommercialCustomerBatch;
use App\Models\CommercialReminderState;
use App\Models\Permission;
use App\Models\Role;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Commercial\CommercialSettings;
use App\Services\Commercial\Customers\CustomerCadence;
use App\Services\Commercial\Customers\CustomerUploadReminders;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

/** Cadence and reminders, the personal-data housekeeping, the settings and the benchmark's safety guard. */
class CustomerHousekeepingTest extends CustomerTestCase
{
    protected function age(CommercialCustomerBatch $batch, int $days): void
    {
        $when = Carbon::now()->subDays($days);
        $batch->forceFill(['imported_at' => $when, 'created_at' => $when])->save();
    }

    // ---------------------------------------------------------------- cadence and reminders

    public function test_the_cadence_defaults_to_the_configured_one_and_a_change_is_audited(): void
    {
        $cadence = app(CustomerCadence::class);

        $this->assertSame('monthly', $cadence->forDistrict($this->sowutuom->id));
        $this->assertSame(35, $cadence->limitDays('monthly'));
        $this->assertSame(10, $cadence->limitDays('weekly'));
        $this->assertNull($cadence->limitDays('off'));

        $cadence->set($this->sowutuom->id, 'weekly', $this->superAdmin()->id);
        $this->assertSame('weekly', $cadence->forDistrict($this->sowutuom->id));
        $this->assertTrue($cadence->isCustom($this->sowutuom->id));
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.customer_cadence_changed')->count());

        $cadence->set($this->sowutuom->id, null, null);
        $this->assertFalse($cadence->isCustom($this->sowutuom->id));
        $this->assertSame('monthly', $cadence->forDistrict($this->sowutuom->id));
    }

    public function test_a_district_is_overdue_by_its_own_cadence(): void
    {
        $batch = $this->loadCustomers($this->simpleSpec(1, 3));
        $reminders = app(CustomerUploadReminders::class);
        $this->age($batch, 20);

        $this->assertSame([], $reminders->overdue(), '20 days is fine for a monthly district');

        app(CustomerCadence::class)->set($this->sowutuom->id, 'weekly', null);
        $late = $reminders->overdue();
        $this->assertCount(1, $late);
        $this->assertSame('Sowutuom', $late[0]['district']);
        $this->assertSame(20, $late[0]['days']);
        $this->assertSame(10, $late[0]['limit']);

        app(CustomerCadence::class)->set($this->sowutuom->id, 'off', null);
        $this->assertSame([], $reminders->overdue(), 'not expected: never overdue');
    }

    public function test_a_district_that_never_uploaded_is_not_overdue(): void
    {
        $this->assertSame([], app(CustomerUploadReminders::class)->overdue());
    }

    public function test_the_officers_of_the_region_are_told_once_and_not_again_until_the_repeat_interval(): void
    {
        $batch = $this->loadCustomers($this->simpleSpec(1, 3));
        $this->age($batch, 40);
        $west = $this->officer('900090', $this->accraWest, $this->sowutuom);
        $ashanti = $this->officer('900091', $this->ashanti, $this->kumasi);
        $reminders = app(CustomerUploadReminders::class);

        $first = $reminders->remind();

        $this->assertSame(1, $first['overdue']);
        $this->assertSame(1, $first['reminded']);
        Notification::assertSentTo($west, GeneralDatabaseNotification::class);
        Notification::assertNotSentTo($ashanti, GeneralDatabaseNotification::class);
        $this->assertSame(1, CommercialReminderState::query()->where('report_type', 'customer_list:'.$this->sowutuom->id)->count());

        $again = $reminders->remind(now()->addDay());
        $this->assertSame(0, $again['reminded'], 'reminded yesterday');
        $this->assertSame(1, $again['skipped']);

        $later = $reminders->remind(now()->addDays(8));
        $this->assertSame(1, $later['reminded'], 'the repeat interval (7 days) has passed');

        // a dry run sends nothing and records nothing
        CommercialReminderState::query()->delete();
        $before = count(Notification::sent($west, GeneralDatabaseNotification::class));
        $reminders->remind(dryRun: true);
        $this->assertSame($before, count(Notification::sent($west, GeneralDatabaseNotification::class)));
        $this->assertSame(0, CommercialReminderState::query()->count());
    }

    public function test_reminders_can_be_switched_off(): void
    {
        $this->age($this->loadCustomers($this->simpleSpec(1, 3)), 60);
        $this->officer('900092', $this->accraWest, $this->sowutuom);
        config(['gwl.commercial_reminders_enabled' => false]);

        $this->assertSame(0, app(CustomerUploadReminders::class)->remind()['reminded']);
        Notification::assertSentTimes(GeneralDatabaseNotification::class, 0);
    }

    public function test_the_uploads_screen_shows_the_overdue_districts_of_the_viewers_region_only(): void
    {
        $this->age($this->loadCustomers($this->simpleSpec(1, 3)), 50);
        $this->age($this->loadCustomers($this->simpleSpec(101, 103, ['region' => 'ASHANTI', 'district' => 'KUMASI'], '2001')), 50);

        Livewire::actingAs($this->officer('900093', $this->accraWest, $this->sowutuom))->test(Uploads::class)->assertSee('1 district overdue')->assertSee('Sowutuom')->assertDontSee('Kumasi');
        Livewire::actingAs($this->superAdmin())->test(Uploads::class)->assertSee('2 districts overdue');
    }

    public function test_the_reminder_command_runs(): void
    {
        $this->age($this->loadCustomers($this->simpleSpec(1, 3)), 50);
        $this->officer('900094', $this->accraWest, $this->sowutuom);

        $this->artisan('commercial:remind-customer-uploads --dry-run')->expectsOutputToContain('1 overdue district(s): would send 1 reminder')->assertSuccessful();
    }

    // ---------------------------------------------------------------- lookups screen

    public function test_a_pending_category_is_reviewed_on_the_lookups_screen(): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [ReportWorkbooks::customer(1, ['category' => '999'])]]]]);
        $id = (int) DB::table('commercial_customer_categories')->where('code', '999')->value('id');
        $this->assertTrue((bool) DB::table('commercial_customer_categories')->where('id', $id)->value('is_pending'));

        $page = Livewire::actingAs($this->superAdmin())->test(Lookups::class);
        $page->assertSee('New in a file')->assertSee('1 code(s) found in uploaded files');
        $page->set("categories.{$id}.name", 'WORKSHOP SALES')->set("categories.{$id}.group", 'commercial')->call('saveCategories');

        $row = DB::table('commercial_customer_categories')->where('id', $id)->first();
        $this->assertSame('WORKSHOP SALES', $row->name);
        $this->assertFalse((bool) $row->is_pending);
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.customer_categories_changed')->count());
    }

    public function test_confirming_a_status_meaning_changes_what_every_screen_shows(): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [ReportWorkbooks::customer(1, ['status' => 'VACN'])]]]]);
        $id = (int) DB::table('commercial_customer_statuses')->where('code', 'VACN')->value('id');

        $page = Livewire::actingAs($this->superAdmin())->test(Lookups::class);
        $page->set("statuses.{$id}.label", 'Vacant premises')->set("statuses.{$id}.confirmed", true)->call('saveStatuses');

        $row = collect(app(\App\Services\Commercial\Customers\CustomerListService::class)->page(['district_id' => $this->sowutuom->id])['rows'])->first();
        $this->assertSame('Vacant premises (VACN)', $row['status']);
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.customer_statuses_changed')->count());
    }

    public function test_only_a_settings_manager_can_use_the_lookups_screen(): void
    {
        Livewire::actingAs($this->officer())->test(Lookups::class)->assertForbidden();
        Livewire::actingAs($this->superAdmin())->test(Lookups::class)->assertOk();
    }

    // ---------------------------------------------------------------- purge

    public function test_the_purge_removes_leftovers_of_unfinished_batches_and_old_exports_but_not_live_data(): void
    {
        $live = $this->loadCustomers($this->simpleSpec(1, 4));

        // a batch that failed a fortnight ago, with staged personal data and its workbook still on disk
        Storage::disk('local')->put('commercial/customer-imports/old.xlsx', 'x');
        $stale = CommercialCustomerBatch::query()->create([
            'district_id' => $this->odorkor->id, 'region_id' => $this->accraWest->id, 'as_of_date' => '2026-09-01', 'file_hash' => hash('sha256', 'old'), 'status' => 'failed',
            'file_path' => 'commercial/customer-imports/old.xlsx', 'period_type' => 'monthly',
        ]);
        $stale->forceFill(['updated_at' => now()->subDays(14)])->saveQuietly();
        DB::table('commercial_customer_staging')->insert(['batch_id' => $stale->id, 'row_no' => 1, 'account_no' => '000000000001', 'route_id' => 1, 'category_id' => 1, 'status_id' => 1, 'meter_status_id' => 1, 'attributes_hash' => 'x', 'contact_hash' => 'y', 'account_name' => 'Someone Private']);
        Storage::disk('local')->put('commercial/customer-exports/old.xlsx', 'x');
        touch(Storage::disk('local')->path('commercial/customer-exports/old.xlsx'), now()->subDays(10)->timestamp);

        Artisan::call('commercial:customers:purge', ['--dry-run' => true]);
        $this->assertSame(1, DB::table('commercial_customer_staging')->count(), 'a dry run deletes nothing');

        Artisan::call('commercial:customers:purge');

        $this->assertSame(0, DB::table('commercial_customer_staging')->count());
        Storage::disk('local')->assertMissing('commercial/customer-imports/old.xlsx');
        Storage::disk('local')->assertMissing('commercial/customer-exports/old.xlsx');
        $this->assertNull($stale->fresh()->file_path);
        $this->assertSame(4, $this->customerCount(), 'live customers are untouched');
        $this->assertSame(4, DB::table('commercial_customer_contacts')->count(), 'and so are their contacts: retention is off by default');
        $this->assertSame(CommercialCustomerBatch::STATUS_IMPORTED, $live->fresh()->status);
    }

    public function test_contact_retention_deletes_only_the_contacts_of_accounts_missing_for_long_enough(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));
        $second = $this->loadCustomers($this->simpleSpec(1, 3), '2026-11-05');
        $this->assertSame(2, $second->rows_missing);

        config(['gwl.commercial_customer_contact_retention_days' => 90]);
        Artisan::call('commercial:customers:purge');
        $this->assertSame(5, DB::table('commercial_customer_contacts')->count(), 'missing for less than 90 days: kept');

        $second->forceFill(['finished_at' => now()->subDays(120)])->save();
        Artisan::call('commercial:customers:purge');

        $this->assertSame(3, DB::table('commercial_customer_contacts')->count(), 'the two accounts missing for 120 days lost their contact details');
        $this->assertSame(5, $this->customerCount(), 'the accounts and their figures stay');
        $this->assertNull(DB::table('commercial_customer_contacts')->where('customer_id', $this->customerId(5))->first());
    }

    // ---------------------------------------------------------------- settings, seeds, guard

    public function test_every_customer_setting_has_a_default_an_env_line_and_validates(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));
        $definitions = collect(CommercialSettings::definitions())->filter(fn ($d) => $d['group'] === 'customers');
        $this->assertGreaterThanOrEqual(8, $definitions->count());

        foreach ($definitions->keys() as $key) {
            $this->assertNotNull(config('gwl.'.$key), "{$key} has no default in config/gwl.php");
            $this->assertStringContainsString('GWL_'.strtoupper($key).'=', $envExample, "{$key} is missing from .env.example");
        }

        $settings = app(CommercialSettings::class);
        $settings->save(['commercial_customer_contact_retention_days' => 365, 'commercial_customer_count_change_warn_pct' => 30], $this->superAdmin()->id);
        $this->assertSame(365, config('gwl.commercial_customer_contact_retention_days'));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $settings->save(['commercial_customer_undo_keep_batches' => 9], $this->superAdmin()->id);
    }

    public function test_the_permission_migration_grants_what_the_seeder_grants_and_is_safe_to_run_again(): void
    {
        $migration = require base_path('database/migrations/2026_10_09_100004_seed_commercial_customer_permissions.php');
        $grants = fn () => Role::query()->with('permissions')->orderBy('name')->get()->mapWithKeys(fn ($r) => [$r->name => $r->permissions->pluck('name')->filter(fn ($n) => str_starts_with($n, 'commercial.view_customer'))->sort()->values()->all()])->all();

        $fromSeeder = $grants();
        $migration->up();
        $migration->up();

        $this->assertSame($fromSeeder, $grants());
        $this->assertSame(['commercial.view_customer_analytics', 'commercial.view_customer_details'], $fromSeeder['super_admin']);
        $this->assertSame(['commercial.view_customer_analytics'], $fromSeeder['commercial_officer']);
        $this->assertSame(2, Permission::query()->where('name', 'like', 'commercial.view_customer_%')->count());
    }

    public function test_the_lookups_are_seeded_with_the_reference_data_and_the_unconfirmed_statuses_flagged(): void
    {
        $this->assertSame(27, DB::table('commercial_customer_categories')->whereNotNull('code')->count());
        $this->assertSame(1, DB::table('commercial_customer_categories')->where('is_unknown', true)->count());
        $this->assertSame('domestic', DB::table('commercial_customer_categories')->where('code', '611')->value('category_group'));
        $this->assertTrue((bool) DB::table('commercial_customer_categories')->where('code', '611')->value('group_is_proposed'), 'the grouping is a proposal');

        $statuses = DB::table('commercial_customer_statuses')->get()->keyBy('code');
        $this->assertSame(8, $statuses->count());
        $this->assertTrue((bool) $statuses['ACTB']->is_billing);
        $this->assertTrue((bool) $statuses['ACTB']->meaning_confirmed);

        foreach (['TRFR', 'VACN', 'DISO', 'NFLO'] as $code) {
            $this->assertFalse((bool) $statuses[$code]->meaning_confirmed, "{$code} must stay unconfirmed");
            $this->assertSame($code, $statuses[$code]->label, 'shown as its raw code');
        }

        $this->assertSame(['F', 'N', 'W'], DB::table('commercial_meter_statuses')->orderBy('code')->pluck('code')->all());
    }

    public function test_the_benchmark_refuses_a_database_that_is_not_a_scratch_one(): void
    {
        $this->artisan('commercial:customers:benchmark', ['--driver' => 'mysql', '--database' => 'erpproject'])
            ->expectsOutputToContain('Refusing to use "erpproject"')
            ->assertFailed();

        // a name that is not a plain identifier, and (in the test suite) any MySQL database at all, are refused before a server is contacted
        $this->artisan('commercial:customers:benchmark', ['--driver' => 'mysql', '--database' => 'x`; DROP DATABASE y; --bench'])->expectsOutputToContain('Refusing to use')->assertFailed();
        $this->artisan('commercial:customers:benchmark', ['--driver' => 'mysql', '--database' => 'erp_commercial_bench'])->expectsOutputToContain('Refusing to create a MySQL database from the test suite')->assertFailed();
    }
}
