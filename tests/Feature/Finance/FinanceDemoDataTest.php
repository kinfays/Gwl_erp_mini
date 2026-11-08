<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FinCatalogOverride;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvEvent;
use App\Models\FinPvType;
use App\Models\FinSupplier;
use App\Models\User;
use App\Services\Finance\FinanceDemoDataService;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PayeeStatusService;
use App\Services\Finance\PvAnalyticsService;
use App\Services\Finance\PvBacklog;
use Database\Seeders\FinanceAuditDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/** finance:demo-data: realistic data through the real services, guarded, repeatable-safe and removable without touching anything else. */
class FinanceDemoDataTest extends FinanceTestCase
{
    private function demo(): FinanceDemoDataService
    {
        return app(FinanceDemoDataService::class);
    }

    /** Locations in the fixtures: Head Office, Accra West (Sowutuom) and Ashanti (Kumasi). */
    private function keys(): array
    {
        return Pv::query()->where('pv_no_key', 'like', 'DEMO-%')->pluck('pv_no')->map(fn (string $no) => explode('-', $no)[1])->unique()->sort()->values()->all();
    }

    private function seedDemo(bool $withUsers = false): array
    {
        return $this->demo()->seed($withUsers);
    }

    /** A fingerprint of everything that is NOT demo, to compare before and after. */
    private function realWorld(): array
    {
        return [
            'employees' => Employee::query()->where('staff_id', 'not like', 'DEMO-%')->orderBy('id')->get()->map->getAttributes()->all(),
            'users' => User::query()->where('staff_id', 'not like', 'DEMO-%')->orderBy('id')->get()->map(fn (User $u) => $u->only(['id', 'staff_id', 'is_active', 'full_name']))->all(),
            'vouchers' => Pv::query()->where('pv_no_key', 'not like', 'DEMO-%')->orderBy('id')->get()->map->getAttributes()->all(),
            'events' => FinPvEvent::query()->whereIn('pv_id', Pv::query()->where('pv_no_key', 'not like', 'DEMO-%')->select('id'))->orderBy('id')->get()->map->getAttributes()->all(),
            'suppliers' => FinSupplier::query()->where('name', 'not like', '% (Demo)')->orderBy('id')->get()->map->getAttributes()->all(),
            'types' => FinPvType::query()->where('name', 'not like', '%(Demo)')->orderBy('id')->get()->map->getAttributes()->all(),
            'overrides' => FinCatalogOverride::query()->whereIn('updated_by', User::query()->where('staff_id', 'not like', 'DEMO-%')->select('id'))->orderBy('id')->get()->map->getAttributes()->all(),
            'audit' => AuditLog::query()->whereIn('user_id', User::query()->where('staff_id', 'not like', 'DEMO-%')->select('id'))->count(),
        ];
    }

    // ------------------------------------------------------------------ guards

    public function test_it_refuses_in_production_when_the_module_is_off_and_for_users_outside_local_or_testing(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('finance:demo-data')->expectsOutputToContain('Refusing to run in production')->assertExitCode(1);
        $this->artisan('finance:demo-data', ['--remove' => true])->expectsOutputToContain('Refusing to run in production')->assertExitCode(1);

        $this->app['env'] = 'testing';
        config(['gwl.finance_audit_enabled' => false]);
        $this->artisan('finance:demo-data')->expectsOutputToContain('switched off')->assertExitCode(1);

        config(['gwl.finance_audit_enabled' => true]);
        $this->app['env'] = 'staging';
        $this->artisan('finance:demo-data', ['--with-users' => true])->expectsOutputToContain('only runs in the local or testing environment')->assertExitCode(1);

        $this->assertSame(0, Pv::query()->count());
        $this->assertSame(0, User::query()->where('staff_id', 'like', 'DEMO-%')->count());

        // Staging may have the data (without sign-in accounts); the seeder is guarded the same way.
        $this->app['env'] = 'production';
        (new FinanceAuditDemoSeeder)->run();
        $this->assertSame(0, Pv::query()->count());

        $this->app['env'] = 'testing';
        (new FinanceAuditDemoSeeder)->run();
        $this->assertSame(180, Pv::query()->count(), 'the seeder makes the same data when allowed');
    }

    public function test_the_seeder_is_not_part_of_the_database_seeder(): void
    {
        $this->assertStringNotContainsString('FinanceAuditDemoSeeder', file_get_contents(database_path('seeders/DatabaseSeeder.php')));
    }

    // ------------------------------------------------------------------ what it makes

    public function test_it_makes_sixty_vouchers_for_head_office_and_every_region_through_every_status_and_path(): void
    {
        $result = $this->seedDemo();

        $this->assertFalse($result['skipped']);
        $this->assertSame(3, $result['locations']);
        $this->assertSame(180, Pv::query()->count());
        $this->assertSame(['AW', 'AS', 'HO'] === $this->keys() ? ['AW', 'AS', 'HO'] : $this->keys(), $this->keys());
        $this->assertCount(3, $this->keys());
        $this->assertContains('HO', $this->keys());

        foreach ($this->keys() as $key) {
            $mine = Pv::query()->where('pv_no_key', 'like', "DEMO-{$key}-%");
            $this->assertSame(60, (clone $mine)->count(), $key);
            $this->assertSame('DEMO-'.$key.'-0001', (clone $mine)->orderBy('id')->value('pv_no'));
            $this->assertSame('DEMO-'.$key.'-0060', (clone $mine)->orderByDesc('id')->value('pv_no'));

            $byStatus = (clone $mine)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all();
            foreach (array_keys(Pv::STATUSES) as $status) {
                $this->assertGreaterThan(0, $byStatus[$status] ?? 0, "{$key} has {$status}");
            }

            // All four payee kinds, and a spread of types.
            $kinds = (clone $mine)->distinct()->pluck('payee_kind')->sort()->values()->all();
            $this->assertSame(['district', 'employee', 'retiree', 'supplier'], $kinds, $key);
            $this->assertGreaterThanOrEqual(6, (clone $mine)->distinct()->count('type_id'), "{$key}: most types are used");
            $this->assertSame(2, (clone $mine)->distinct()->count('category_id'), "{$key}: both categories");

            // Queried once and twice (different reasons), resubmitted, tick withdrawn and re-reviewed.
            $ids = (clone $mine)->pluck('id');
            $queriedTwice = FinPvEvent::query()->whereIn('pv_id', $ids)->where('action', 'queried')->selectRaw('pv_id, COUNT(*) as n')->groupBy('pv_id')->get()->filter(fn ($row) => $row->n >= 2);
            $this->assertGreaterThan(0, $queriedTwice->count(), "{$key}: some vouchers are queried twice");
            $actions = FinPvEvent::query()->whereIn('pv_id', $ids)->pluck('action')->countBy()->all();
            foreach (['submitted', 'queried', 'resubmitted', 'audit_acknowledged', 'tick_withdrawn', 'finance_acknowledged', 'voided'] as $action) {
                $this->assertGreaterThan(0, $actions[$action] ?? 0, "{$key} has {$action} events");
            }
            $this->assertGreaterThanOrEqual(3, FinPvEvent::query()->whereIn('pv_id', $ids)->where('action', 'queried')->distinct()->count('query_reason_code'), "{$key}: different reasons");

            // At least two vouchers paid to staff who have since left service.
            $flagged = (clone $mine)->get()->filter(fn (Pv $pv) => app(PayeeStatusService::class)->warningFor($pv) !== null);
            $this->assertGreaterThanOrEqual(2, $flagged->count(), "{$key}: payee left service");
        }
    }

    public function test_the_ageing_buckets_and_the_history_look_genuine_in_the_reports(): void
    {
        $this->seedDemo();
        $ho = ['region_id' => $this->headOffice->region_id, 'district_id' => $this->headOffice->id];
        $officer = User::query()->where('staff_id', 'DEMO-HO-FO')->firstOrFail();
        $report = app(PvAnalyticsService::class)->report($officer, FinanceScope::AS_FINANCE, $ho, '2026-05-01', '2026-11-30');

        // Vouchers With Audit are spread over all four ageing buckets.
        $this->assertSame(4, count($report['waiting']['aging']['counts']));
        foreach ($report['waiting']['aging']['counts'] as $label => $count) {
            $this->assertGreaterThan(0, $count, "aging bucket {$label}");
        }

        // Turnaround is worked out from real, dated events, and the months run back about six months.
        foreach (['audit_response', 'finance_response', 'acknowledge', 'end_to_end'] as $step) {
            $this->assertNotNull($report['audit']['turnaround'][$step]['days'], $step);
        }

        $this->assertGreaterThanOrEqual(5, collect($report['months']['rows'])->where('count', '>', 0)->count());
        $this->assertGreaterThan(0, $report['tiles']['drafts']);
        $this->assertGreaterThan(0, $report['tiles']['voided']);

        // Every event is dated in the past and in order, and the audit rows are real ones.
        $events = FinPvEvent::query()->orderBy('pv_id')->orderBy('id')->get();
        $this->assertTrue($events->every(fn (FinPvEvent $e) => $e->created_at->lte(now())));
        $this->assertTrue($events->groupBy('pv_id')->every(fn ($list) => $list->pluck('created_at')->map->getTimestamp()->values()->all() === $list->pluck('created_at')->map->getTimestamp()->sort()->values()->all()));
        $this->assertGreaterThan(100, AuditLog::query()->where('action', 'like', '%pv_%')->count());

        // The longest wait With Audit is 15+ days and the newest under 3 (Reports "waiting right now").
        $items = app(PvBacklog::class)->items(app(FinanceScope::class)->atLocation(app(FinanceScope::class)->pvs($officer), $ho));
        $audit = $items->where('kind', 'audit');
        $this->assertGreaterThanOrEqual(15, $audit->max('days'));
        $this->assertLessThanOrEqual(2, $audit->min('days'));
    }

    public function test_the_first_region_has_a_location_owned_type_a_renamed_type_and_a_switched_off_type(): void
    {
        $this->seedDemo();
        $regionKeys = array_values(array_diff($this->keys(), ['HO']));
        $unitOwned = FinPvType::query()->whereNotNull('unit')->get();

        $this->assertCount(1, $unitOwned, 'one location-owned type, in one location');
        $this->assertStringContainsString('(Demo)', $unitOwned->first()->name);
        $this->assertSame(2, FinCatalogOverride::query()->count());
        $this->assertNotNull(FinCatalogOverride::query()->whereNotNull('display_name')->first());
        $this->assertSame(false, (bool) FinCatalogOverride::query()->whereNotNull('is_active')->value('is_active'));

        // The vouchers keep what they used, and the other locations still see the standard list.
        $unit = $unitOwned->first()->unit;
        $this->assertGreaterThan(0, Pv::query()->where('type_id', $unitOwned->first()->id)->count());
        $this->assertCount(2, FinCatalogOverride::query()->where('unit', $unit)->get());
        $this->assertCount(2, $regionKeys);
    }

    public function test_notices_are_suppressed_and_the_clock_and_user_are_put_back(): void
    {
        Notification::fake();
        $before = now()->toIso8601String();

        $this->seedDemo();

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('notifications')->count(), 'no bell notices for anyone');
        $this->assertSame($before, now()->toIso8601String(), 'the clock is back where it was');
        $this->assertNull(auth()->user());
    }

    public function test_suppliers_are_company_wide_marked_and_some_have_only_a_name(): void
    {
        $this->seedDemo();

        $suppliers = FinSupplier::query()->get();
        $this->assertCount(25, $suppliers);
        $this->assertTrue($suppliers->every(fn (FinSupplier $s) => str_ends_with($s->name, ' (Demo)')));
        $this->assertGreaterThan(5, $suppliers->whereNotNull('tin')->count());
        $this->assertGreaterThan(5, $suppliers->whereNull('tin')->count());
        $this->assertNotNull($suppliers->whereNotNull('phone')->first());
        $this->assertTrue($suppliers->whereNotNull('tin')->every(fn (FinSupplier $s) => preg_match('/^[A-Z]\d{10}$/', $s->tin) === 1));
    }

    // ------------------------------------------------------------------ accounts

    public function test_without_with_users_the_demo_accounts_are_inactive_and_with_it_they_are_usable_once(): void
    {
        $quiet = $this->seedDemo();
        $this->assertSame([], $quiet['credentials']);
        $accounts = User::query()->where('staff_id', 'like', 'DEMO-%')->whereIn('staff_id', ['DEMO-HO-FO', 'DEMO-HO-FM', 'DEMO-HO-AO', 'DEMO-HO-AM'])->get();
        $this->assertCount(4, $accounts);
        $this->assertTrue($accounts->every(fn (User $u) => ! $u->is_active), 'nobody can sign in');
        $this->assertSame(['audit_manager', 'audit_officer', 'finance_manager', 'finance_officer'], $accounts->flatMap(fn (User $u) => $u->roles->pluck('name'))->reject(fn ($n) => $n === 'employee')->sort()->values()->all());

        $this->demo()->remove();
        $loud = $this->seedDemo(true);

        $this->assertCount(12, $loud['credentials'], 'four accounts in each of three locations');
        foreach ($loud['credentials'] as $row) {
            $user = User::query()->where('staff_id', $row['staff_id'])->firstOrFail();
            $this->assertTrue($user->is_active);
            $this->assertTrue($user->must_change_password, 'a new password is demanded at first sign-in');
            $this->assertTrue(Hash::check($row['password'], $user->password), 'the printed password is the one set');
            $this->assertGreaterThanOrEqual(14, strlen($row['password']));
            $this->assertStringStartsWith('DEMO-', $user->staff_id);
            $this->assertStringContainsString('Demo', $user->full_name, 'clearly marked');
        }

        $this->assertSame(12, count(array_unique(array_column($loud['credentials'], 'password'))), 'a different password for each');
    }

    public function test_the_demo_accounts_hold_the_real_roles_and_work_in_their_own_location(): void
    {
        $this->seedDemo(true);
        $fo = User::query()->where('staff_id', 'DEMO-HO-FO')->firstOrFail();
        $am = User::query()->where('staff_id', 'DEMO-HO-AM')->firstOrFail();
        $scope = app(FinanceScope::class);

        $this->assertSame(60, $scope->pvs($fo)->count());
        $ao = User::query()->where('staff_id', 'DEMO-HO-AO')->firstOrFail();
        $this->assertSame(60 - 6 - 1, $scope->pvs($ao, FinanceScope::AS_AUDIT)->count(), 'Finance reads drafts (6, and a voided draft); Audit never does');
        $this->assertSame(0, $scope->pvs($this->person('X1', $this->kumasi, ['employee', 'finance_officer']))->where('pv_no', 'like', 'DEMO-HO-%')->count());

        // The Audit manager sees only vouchers an Audit officer worked on.
        $unreviewed = Pv::query()->where('pv_no_key', 'like', 'DEMO-HO-%')->where('status', Pv::WITH_AUDIT)->whereNotIn('id', FinPvEvent::query()->whereIn('action', FinPvEvent::REVIEWING)->select('pv_id'))->count();
        $this->assertGreaterThan(0, $unreviewed);
        $this->assertSame(0, $scope->pvs($am, FinanceScope::AS_AUDIT)->whereIn('id', Pv::query()->where('status', Pv::WITH_AUDIT)->whereNotIn('id', FinPvEvent::query()->whereIn('action', FinPvEvent::REVIEWING)->select('pv_id'))->select('id'))->count());
    }

    // ------------------------------------------------------------------ re-running and removing

    public function test_running_it_again_adds_nothing(): void
    {
        $this->seedDemo();
        $counts = [Pv::query()->count(), FinPvEvent::query()->count(), FinSupplier::query()->count(), User::query()->count(), Employee::query()->count(), AuditLog::query()->count()];

        $again = $this->seedDemo();
        $this->artisan('finance:demo-data')->expectsOutputToContain('already exist')->assertExitCode(0);

        $this->assertTrue($again['skipped']);
        $this->assertSame($counts, [Pv::query()->count(), FinPvEvent::query()->count(), FinSupplier::query()->count(), User::query()->count(), Employee::query()->count(), AuditLog::query()->count()]);
    }

    public function test_a_normal_run_and_a_removal_leave_everything_that_is_not_demo_untouched(): void
    {
        // Real data of every kind the demo run touches: staff and users, a voucher and its events, a supplier, a catalogue override, audit rows.
        $realFin = $this->finance('REAL-F1', $this->sowutuom);
        $realManager = $this->financeManager('REAL-FM1', $this->sowutuom);
        $this->employee('REAL-S1', $this->sowutuom);
        $retired = $this->employee('REAL-R1', $this->sowutuom);
        $retired->update(['is_active' => false, 'deactivation_reason' => 'retired']);
        $supplier = app(\App\Services\Finance\SupplierService::class)->create($realFin, ['name' => 'Real Traders Ltd']);
        $this->actingAs($realFin);
        $pv = $this->sent($realFin, ['pv_no' => 'REAL-PV-1', 'payee_kind' => 'supplier', 'supplier_id' => $supplier->id]);
        $this->actingAs($realManager);
        app(\App\Services\Finance\PvCatalogService::class)->renameType($realManager, FinPvType::query()->where('name', 'Medical')->value('id'), 'Real Medical');
        $kept = app(\App\Services\Finance\SupplierService::class)->create($realFin, ['name' => 'Used Elsewhere (Demo)']);
        $this->actingAs($realFin);
        $this->sent($realFin, ['pv_no' => 'REAL-PV-2', 'payee_kind' => 'supplier', 'supplier_id' => $kept->id]);
        auth()->logout();

        $before = $this->realWorld();
        $this->assertNotEmpty($before['vouchers']);

        // A normal run changes none of it (existing staff are only READ, as payees).
        $this->seedDemo();
        $this->assertSame($before, $this->realWorld(), 'a demo run leaves the real data as it was');
        $realStaff = Employee::query()->where('staff_id', 'not like', 'DEMO-%')->pluck('id');
        $this->assertGreaterThan(0, Pv::query()->where('pv_no_key', 'like', 'DEMO-%')->whereIn('payee_employee_id', $realStaff)->count(), 'existing staff of a location are used as payees');

        // Removal deletes the demo rows and nothing else.
        $result = $this->demo()->remove();

        $this->assertSame(180, $result['vouchers']);
        $this->assertSame(1, $result['suppliers_kept'], 'a demo-named supplier a real voucher uses is kept');
        $this->assertSame(0, Pv::query()->where('pv_no_key', 'like', 'DEMO-%')->count());
        $this->assertSame(0, FinPvEvent::query()->whereNotIn('pv_id', Pv::query()->select('id'))->count());
        $this->assertSame(0, User::query()->where('staff_id', 'like', 'DEMO-%')->count());
        $this->assertSame(0, Employee::query()->where('staff_id', 'like', 'DEMO-%')->count());
        $this->assertSame(0, FinSupplier::query()->where('name', 'like', '% (Demo)')->whereKeyNot($kept->id)->count());
        $this->assertSame(0, FinPvType::query()->whereNotNull('unit')->where('name', 'like', '%(Demo)')->count());
        $this->assertSame(1, FinCatalogOverride::query()->count(), 'only the real manager\'s override is left');
        $this->assertSame(0, DB::table('audit_logs')->whereNotIn('user_id', User::query()->select('id'))->whereNotNull('user_id')->count());

        $after = $this->realWorld();
        $this->assertSame($before, $after, 'after the removal the real data is exactly as it was');
        $this->assertSame($before['vouchers'], $after['vouchers']);
        $this->assertSame($before['events'], $after['events']);
        $this->assertSame($before['suppliers'], $after['suppliers']);
        $this->assertSame($before['overrides'], $after['overrides']);
        $this->assertSame($before['audit'], $after['audit']);
        $this->assertSame($before['types'], $after['types']);
        $this->assertSame(Pv::query()->whereIn('id', [$pv->id])->count(), 1);
        $this->assertSame(2, Pv::query()->count());
    }

    public function test_removing_when_there_is_nothing_to_remove_is_harmless_and_a_run_after_removal_works_again(): void
    {
        $empty = $this->demo()->remove();
        $this->assertSame(['vouchers' => 0, 'suppliers' => 0, 'suppliers_kept' => 0, 'catalogue' => 0, 'accounts' => 0, 'staff' => 0], $empty);

        $this->seedDemo();
        $this->artisan('finance:demo-data', ['--remove' => true])->expectsOutputToContain('Done: 180 vouchers')->assertExitCode(0);
        $this->assertSame(0, Pv::query()->count());

        $this->assertFalse($this->seedDemo()['skipped']);
        $this->assertSame(180, Pv::query()->count());
    }
}
