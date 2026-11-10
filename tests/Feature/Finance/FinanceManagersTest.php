<?php

namespace Tests\Feature\Finance;

use App\Livewire\Audit\Queue;
use App\Livewire\Finance\VoucherForm;
use App\Models\AuditLog;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvEvent;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Finance\FinanceExportService;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvAnalyticsService;
use App\Services\Finance\SupplierService;
use App\Services\Uac\RoleGrantPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Step A: the Finance manager (reads all, writes no voucher) and the Audit manager (sees only vouchers Audit has worked on). */
class FinanceManagersTest extends FinanceTestCase
{
    private function slugs(string $role): array
    {
        return Role::query()->where('name', $role)->firstOrFail()->permissions()->pluck('name')->all();
    }

    private function scope(): FinanceScope
    {
        return app(FinanceScope::class);
    }

    private function west(): array
    {
        return ['region_id' => $this->sowutuom->region_id, 'district_id' => $this->sowutuom->id];
    }

    private function assertForbiddenCall(callable $call, string $message = ''): void
    {
        try {
            $call();
            $this->fail('Expected a 403. '.$message);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode(), $message);
        }
    }

    // ------------------------------------------------------------------ roles and permissions

    public function test_the_permissions_of_each_role_are_exactly_these(): void
    {
        $this->assertEqualsCanonicalizing(
            ['finance.view', 'finance.view_reports', 'finance.export', 'finance.manage_categories', 'finance.manage_reminders', 'finance.deactivate_suppliers'],
            $this->slugs('finance_manager')
        );
        $this->assertEqualsCanonicalizing(
            ['audit.view_reviewed', 'audit.view_reports', 'audit.export', 'audit.manage_reminders', 'audit.assign'],
            $this->slugs('audit_manager')
        );

        $officer = $this->slugs('finance_officer');
        $this->assertNotContains('finance.manage_categories', $officer, 'the catalogue is the manager\'s');
        $this->assertNotContains('finance.manage_reminders', $officer);
        $this->assertNotContains('finance.deactivate_suppliers', $officer);
        $this->assertEqualsCanonicalizing(
            ['finance.view', 'finance.create_pv', 'finance.void_pv', 'finance.acknowledge', 'finance.manage_suppliers', 'finance.import_suppliers', 'finance.view_reports', 'finance.export'],
            $officer
        );

        $this->assertEqualsCanonicalizing(['audit.view', 'audit.review', 'audit.view_reports', 'audit.export'], $this->slugs('audit_officer'), 'unchanged');

        foreach (['finance_manager' => ['finance.create_pv', 'finance.void_pv', 'finance.acknowledge'], 'audit_manager' => ['audit.review', 'audit.view']] as $role => $never) {
            foreach ($never as $permission) {
                $this->assertNotContains($permission, $this->slugs($role), "{$role} must not hold {$permission}");
            }
        }

        $super = $this->slugs('super_admin');
        foreach (['finance.manage_reminders', 'audit.manage_reminders', 'audit.view_reviewed', 'audit.assign'] as $permission) {
            $this->assertContains($permission, $super);
        }
    }

    public function test_the_manager_roles_are_ict_assignable_unprotected_and_in_neither_location_list(): void
    {
        $policy = app(RoleGrantPolicy::class);

        foreach (['finance_manager' => 'finance', 'audit_manager' => 'audit'] as $name => $module) {
            $role = Role::query()->where('name', $name)->firstOrFail();

            $this->assertTrue((bool) $role->ict_assignable, $name);
            $this->assertFalse((bool) $role->is_protected, $name);
            $this->assertFalse((bool) $role->is_system, $name);
            $this->assertNotContains($name, RoleGrantPolicy::HEAD_OFFICE_ROLES);
            $this->assertNotContains($name, RoleGrantPolicy::REGIONAL_ROLES);

            foreach ([$this->headOffice, $this->sowutuom, $this->kumasi] as $district) {
                $this->assertNull($policy->locationDenial($role, $this->employee('X'.$name.$district->id, $district)), "{$name} at {$district->district_name}");
            }

            $modules = ModuleAccess::query()->where('role_id', $role->id)->where('can_access', true)->pluck('module')->all();
            $other = $module === 'finance' ? 'audit' : 'finance';
            $this->assertContains($module, $modules, "{$name} reaches its own module");
            $this->assertNotContains($other, $modules, "{$name} does not reach the other one");
        }
    }

    public function test_the_migration_takes_the_catalogue_and_reminders_away_from_officers_who_already_had_them(): void
    {
        // Rebuild what a database migrated before this change looks like: the officer holds the catalogue, the old settings
        // permission exists under its old name, and the managers do not exist yet.
        $officer = Role::query()->where('name', 'finance_officer')->firstOrFail();
        $categories = Permission::query()->where('name', 'finance.manage_categories')->firstOrFail();
        $reminders = Permission::query()->where('name', 'finance.manage_reminders')->firstOrFail();
        $reminders->forceFill(['name' => 'finance.manage_settings'])->save();
        $officer->permissions()->syncWithoutDetaching([$categories->id, $reminders->id]);
        $managerIds = Role::query()->whereIn('name', ['finance_manager', 'audit_manager'])->pluck('id');
        DB::table('role_permissions')->whereIn('role_id', $managerIds)->delete();
        DB::table('module_access')->whereIn('role_id', $managerIds)->delete();
        DB::table('roles')->whereIn('id', $managerIds)->delete();
        Permission::query()->whereIn('name', ['audit.manage_reminders', 'audit.view_reviewed'])->delete();

        (require base_path('database/migrations/2026_11_12_000001_add_finance_audit_managers.php'))->up();

        $this->assertNotContains('finance.manage_categories', $this->slugs('finance_officer'));
        $this->assertNotContains('finance.manage_reminders', $this->slugs('finance_officer'));
        $this->assertSame(0, Permission::query()->where('name', 'finance.manage_settings')->count(), 'renamed in place');
        $this->assertContains('finance.manage_reminders', $this->slugs('super_admin'), 'a rename keeps the grants it had');
        $this->assertContains('finance.manage_categories', $this->slugs('finance_manager'));
        $this->assertEqualsCanonicalizing(['audit.view_reviewed', 'audit.view_reports', 'audit.export', 'audit.manage_reminders'], $this->slugs('audit_manager'));
        $this->assertContains('finance.create_pv', $this->slugs('finance_officer'), 'the rest of the officer is untouched');

        // Running it again changes nothing.
        (require base_path('database/migrations/2026_11_12_000001_add_finance_audit_managers.php'))->up();
        $this->assertSame(1, Role::query()->where('name', 'finance_manager')->count());
        $this->assertSame(6, count($this->slugs('finance_manager')));
    }

    // ------------------------------------------------------------------ the Finance manager

    public function test_the_finance_manager_sees_every_voucher_of_the_location_and_nothing_of_another(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $manager = $this->financeManager('FM1', $this->sowutuom);

        $draft = $this->draft($fin, ['pv_no' => 'DRAFT-1']);
        $withAudit = $this->sent($fin, ['pv_no' => 'WA-1']);
        $queried = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'Q-1']);
        $this->actingAs($aud);
        $this->workflow()->auditQuery($aud, $queried, 'duplicate', 'Seen');
        $voided = $this->draft($fin, ['pv_no' => 'VOID-1']);
        $this->workflow()->void($fin, $voided, 'Mistake');
        $other = $this->draft($ash, ['pv_no' => 'ASH-1']);

        $ids = $this->scope()->pvs($manager)->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$draft, $withAudit, $queried, $voided])->pluck('id')->sort()->values()->all(), $ids, 'drafts and every status');
        $this->assertTrue($this->scope()->events($manager)->where('pv_id', $queried->id)->exists());

        $this->withoutVite();
        $this->actingAs($manager)->get(route('finance.home'))->assertOk()->assertSee('DRAFT-1');
        $this->actingAs($manager)->get(route('finance.pvs.show', $draft->id))->assertOk();
        $this->actingAs($manager)->get(route('finance.pvs.show', $other->id))->assertNotFound();
        $this->actingAs($manager)->get(route('finance.reports'))->assertOk();
        $this->actingAs($manager)->get(route('finance.suppliers'))->assertOk();
        $this->actingAs($manager)->get(route('finance.settings'))->assertOk();
        $this->actingAs($manager)->get(route('finance.reminder-settings'))->assertOk();
    }

    public function test_the_finance_manager_cannot_add_edit_send_resubmit_void_or_acknowledge_through_the_service(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $manager = $this->financeManager('FM1', $this->sowutuom);

        $draft = $this->draft($fin, ['pv_no' => 'D-1']);
        $queried = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'Q-1']);
        $ticked = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'T-1']);
        $this->actingAs($aud);
        $queried = $this->workflow()->auditQuery($aud, $queried, 'duplicate', 'Seen');
        $ticked = $this->workflow()->auditAcknowledge($aud, $ticked);
        $this->actingAs($manager);
        $w = $this->workflow();

        $this->assertForbiddenCall(fn () => $w->createDraft($manager, $this->pvData(['pv_no' => 'NEW-1'])), 'create');
        $this->assertForbiddenCall(fn () => $w->update($manager, $draft, $this->pvData(['pv_no' => 'D-1']), $draft->lock_version), 'edit');
        $this->assertForbiddenCall(fn () => $w->submit($manager, $draft, $draft->lock_version), 'send');
        $this->assertForbiddenCall(fn () => $w->resubmit($manager, $queried, $queried->lock_version), 'resubmit');
        $this->assertForbiddenCall(fn () => $w->void($manager, $draft, 'No reason'), 'void');
        $this->assertForbiddenCall(fn () => $w->financeAcknowledge($manager, $ticked), 'acknowledge');

        $this->assertSame(Pv::DRAFT, $draft->fresh()->status);
        $this->assertSame(Pv::QUERIED, $queried->fresh()->status);
        $this->assertSame(Pv::AUDIT_ACKNOWLEDGED, $ticked->fresh()->status);
        $this->assertSame(0, Pv::query()->where('pv_no', 'NEW-1')->count());
        $this->assertSame(0, FinPvEvent::query()->where('actor_user_id', $manager->id)->count());

        // The Audit actions are refused too: the manager holds no review right in either module.
        $this->assertForbiddenCall(fn () => $w->auditAcknowledge($manager, $this->sent($fin, ['pv_no' => 'S-9'])));
    }

    public function test_the_create_and_edit_screens_answer_403_to_the_finance_manager_and_show_no_buttons(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $manager = $this->financeManager('FM1', $this->sowutuom);
        $draft = $this->draft($fin);

        $this->actingAs($manager)->get(route('finance.pvs.create'))->assertForbidden();
        $this->actingAs($manager)->get(route('finance.pvs.edit', $draft->id))->assertForbidden();
        Livewire::actingAs($manager)->test(VoucherForm::class)->assertForbidden();
        Livewire::actingAs($manager)->test(VoucherForm::class, ['pv' => $draft->id])->assertForbidden();

        $this->actingAs($manager)->get(route('finance.pvs.show', $draft->id))->assertOk()->assertDontSee('Send to Audit')->assertDontSee('wire:click="openVoid"', false)->assertDontSee('wire:click="acknowledge"', false)->assertDontSee(route('finance.pvs.edit', $draft->id), false);
        $this->assertNotContains('finance.pvs.create', collect(app(\App\Support\ErpNavigation::class)->build($manager, 'finance')['sidebar'])->pluck('route')->all());

        // And a forced call on the detail component is refused as well.
        Livewire::actingAs($manager)->test(\App\Livewire\Finance\VoucherShow::class, ['pv' => $draft->id])->call('submit')->assertForbidden();
    }

    public function test_the_finance_manager_manages_the_catalogue_reads_suppliers_and_deactivates_them_but_does_not_add_them(): void
    {
        $manager = $this->financeManager('FM1', $this->sowutuom);
        $officer = $this->finance('F1', $this->sowutuom);
        $suppliers = app(SupplierService::class);
        $this->actingAs($officer);
        $supplier = $suppliers->create($officer, ['name' => 'Acme Supplies']);

        $this->assertTrue($this->scope()->can($manager, 'finance.manage_categories'));
        $this->actingAs($manager);
        $this->assertFalse($suppliers->setActive($manager, $supplier->id, false)->is_active, 'deactivating is the manager\'s');
        $this->assertForbiddenCall(fn () => $suppliers->create($manager, ['name' => 'Manager Added']), 'adding suppliers is the officer\'s');
        $this->assertForbiddenCall(fn () => $suppliers->setActive($officer, $supplier->id, true), 'the officer cannot switch one back');
    }

    // ------------------------------------------------------------------ the Audit manager: one condition, everywhere

    /** Builds a location with every kind of voucher the manager may or may not see. @return array<string, Pv> */
    private function books(): array
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);

        $draft = $this->draft($fin, ['pv_no' => 'DRAFT']);
        // UNREVIEWED is assigned to the officer but nobody has acted on it; VOIDED-NEVER was sent and voided before it was assigned.
        $unreviewed = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'UNREVIEWED']);
        $ticked = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'TICKED']);
        $queried = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'QUERIED']);
        $requeried = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'RESUBMITTED']);
        $withdrawn = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'WITHDRAWN']);
        $completed = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'COMPLETED']);
        $voidedAfterQuery = $this->sentAndAssigned($fin, $aud, ['pv_no' => 'VOIDED-QUERIED']);
        $voidedUnreviewed = $this->sent($fin, ['pv_no' => 'VOIDED-NEVER']);

        $this->actingAs($aud);
        $w = $this->workflow();
        $ticked = $w->auditAcknowledge($aud, $ticked);
        $queried = $w->auditQuery($aud, $queried, 'duplicate', 'Seen before');
        $requeried = $w->auditQuery($aud, $requeried, 'other', 'Check');
        $withdrawn = $w->withdrawTick($aud, $w->auditAcknowledge($aud, $withdrawn), 'Wrong amount');
        $completed = $w->auditAcknowledge($aud, $completed);
        $voidedAfterQuery = $w->auditQuery($aud, $voidedAfterQuery, 'other', 'Check');

        $this->actingAs($fin);
        $completed = $w->financeAcknowledge($fin, $completed);
        $requeried = $w->resubmit($fin, $requeried, $requeried->lock_version);
        $voidedAfterQuery = $w->void($fin, $voidedAfterQuery, 'Cancelled');
        $voidedUnreviewed = $w->void($fin, $voidedUnreviewed, 'Cancelled');

        return compact('draft', 'unreviewed', 'ticked', 'queried', 'requeried', 'withdrawn', 'completed', 'voidedAfterQuery', 'voidedUnreviewed', 'fin', 'aud');
    }

    public function test_the_audit_manager_reaches_exactly_the_vouchers_an_audit_officer_has_worked_on(): void
    {
        $b = $this->books();
        $manager = $this->auditManager('AM1', $this->sowutuom);

        $mine = $this->scope()->pvs($manager, FinanceScope::AS_AUDIT)->pluck('pv_no')->sort()->values()->all();
        $this->assertSame(['COMPLETED', 'QUERIED', 'RESUBMITTED', 'TICKED', 'VOIDED-QUERIED', 'WITHDRAWN'], $mine);

        // The officer reads what is assigned to them (the unreviewed one too) but not a voucher nobody assigned (VOIDED-NEVER).
        $officer = $this->scope()->pvs($b['aud'], FinanceScope::AS_AUDIT)->pluck('pv_no')->sort()->values()->all();
        $this->assertSame(['COMPLETED', 'QUERIED', 'RESUBMITTED', 'TICKED', 'UNREVIEWED', 'VOIDED-QUERIED', 'WITHDRAWN'], $officer);

        // The same single condition covers the timeline and the files.
        $this->assertSame(0, $this->scope()->events($manager, FinanceScope::AS_AUDIT)->where('pv_id', $b['unreviewed']->id)->count());
        $this->assertGreaterThan(0, $this->scope()->events($manager, FinanceScope::AS_AUDIT)->where('pv_id', $b['ticked']->id)->count());
        $this->assertFalse($this->scope()->containsPv($manager, $b['unreviewed'], FinanceScope::AS_AUDIT));
        $this->assertTrue($this->scope()->containsPv($manager, $b['requeried'], FinanceScope::AS_AUDIT), 'With Audit again, but already queried once');

        // Another location's manager sees nothing of this one.
        $this->assertSame(0, $this->scope()->pvs($this->auditManager('AM2', $this->kumasi), FinanceScope::AS_AUDIT)->count());
    }

    public function test_an_unreviewed_voucher_answers_404_on_the_detail_screen_the_file_route_and_the_queue_search(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        $pv = $this->workflow()->createDraft($fin, $this->pvData(['pv_no' => 'WITH-FILE']), [UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n")]);
        $pv = $this->workflow()->submit($fin, $pv, $pv->lock_version);
        $attachment = $pv->attachments()->firstOrFail();
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);

        // Never reviewed: invisible.
        $this->actingAs($manager)->get(route('audit.pvs.show', $pv->id))->assertNotFound();
        $this->actingAs($manager)->get(route('audit.pvs.attachment', [$pv->id, $attachment->id]))->assertNotFound();
        Livewire::actingAs($manager)->test(Queue::class)->set('search', 'WITH-FILE')->assertDontSee('WITH-FILE');
        Livewire::actingAs($manager)->test(Queue::class)->set('status', Pv::WITH_AUDIT)->assertDontSee('WITH-FILE');
        $this->actingAs($aud)->get(route('audit.pvs.show', $pv->id))->assertNotFound();   // not assigned yet: invisible to officers too
        $this->assignTo($pv, $aud);
        $this->actingAs($aud)->get(route('audit.pvs.show', $pv->id))->assertOk();
        $this->actingAs($manager)->get(route('audit.pvs.show', $pv->id))->assertNotFound();   // assigned, still nobody has acted: not the manager's to open

        // Once an Audit officer has worked on it, the manager sees it, the timeline with who did what, and the file.
        $this->actingAs($aud);
        $this->workflow()->auditQuery($aud, $pv, 'duplicate', 'Seen before');
        $this->actingAs($manager)->get(route('audit.pvs.show', $pv->id))->assertOk()->assertSee($aud->full_name)->assertDontSee('wire:click="tick"', false)->assertDontSee('wire:click="openQuery"', false)->assertDontSee('wire:click="openWithdraw"', false);
        $this->get(route('audit.pvs.attachment', [$pv->id, $attachment->id]))->assertOk();
        Livewire::actingAs($manager)->test(Queue::class)->assertSee('WITH-FILE');
    }

    public function test_the_audit_manager_cannot_tick_query_or_withdraw_even_by_calling_the_service(): void
    {
        $b = $this->books();
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $w = $this->workflow();
        $this->actingAs($manager);

        $this->assertForbiddenCall(fn () => $w->auditAcknowledge($manager, $b['requeried']), 'tick');
        $this->assertForbiddenCall(fn () => $w->auditQuery($manager, $b['requeried'], 'other', 'x'), 'query');
        $this->assertForbiddenCall(fn () => $w->withdrawTick($manager, $b['ticked'], 'x'), 'withdraw');
        $this->assertForbiddenCall(fn () => $w->financeAcknowledge($manager, $b['ticked']), 'finance side');
        $this->assertSame(0, FinPvEvent::query()->where('actor_user_id', $manager->id)->count());

        // An unreviewed voucher is not even "forbidden": the manager cannot name it.
        $this->assertForbiddenCall(fn () => $w->auditAcknowledge($manager, $b['unreviewed']), 'permission is checked first');
    }

    public function test_the_manager_may_use_audit_reports_and_exports_but_the_register_and_waiting_list_hold_only_worked_vouchers(): void
    {
        $this->withoutVite();
        $b = $this->books();
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $period = ['2026-10-01', '2026-11-30'];
        $exports = app(FinanceExportService::class);

        $register = $exports->build('register', $manager, FinanceScope::AS_AUDIT, $this->west(), $period[0], $period[1], 'Accra West');
        $numbers = collect($register['tables'][0]['rows'])->pluck(1)->sort()->values()->all();
        $this->assertSame(['COMPLETED', 'QUERIED', 'RESUBMITTED', 'TICKED', 'VOIDED-QUERIED', 'WITHDRAWN'], $numbers);
        $this->assertStringNotContainsString('UNREVIEWED', json_encode($register));

        $waiting = $exports->build('waiting', $manager, FinanceScope::AS_AUDIT, $this->west(), $period[0], $period[1], 'Accra West');
        $listed = collect($waiting['tables'][0]['rows'])->pluck(0)->all();
        $this->assertNotContains('UNREVIEWED', $listed);
        $this->assertContains('RESUBMITTED', $listed);

        $this->actingAs($manager)->get(route('audit.reports'))->assertOk();
        $this->actingAs($manager)->get(route('audit.export', ['report' => 'register', 'format' => 'excel', 'from' => $period[0], 'to' => $period[1]]))->assertOk();
        $this->actingAs($manager)->get(route('audit.suppliers'))->assertForbidden();
        $this->assertSame(1, AuditLog::query()->where('action', 'audit.export_register_excel')->count());
    }

    public function test_the_managers_figures_are_location_wide_totals_but_the_list_names_only_worked_vouchers(): void
    {
        $b = $this->books();
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $analytics = app(PvAnalyticsService::class);
        $args = [FinanceScope::AS_AUDIT, $this->west(), '2026-10-01', '2026-11-30'];

        $forOfficer = $analytics->report($b['aud'], ...$args);
        $forManager = $analytics->report($manager, ...$args);

        // Every figure is the same for both: how many vouchers, the money, the statuses, how many are waiting.
        foreach (['tiles', 'audit', 'statuses', 'reasons', 'categories', 'months'] as $key) {
            $this->assertEquals($forOfficer[$key], $forManager[$key], $key);
        }

        $this->assertEquals($forOfficer['waiting']['kinds'], $forManager['waiting']['kinds'], 'how many are waiting includes the unreviewed one');
        $this->assertSame(3, $forManager['waiting']['kinds']['audit']['count'], 'UNREVIEWED + RESUBMITTED + WITHDRAWN wait with Audit');
        $this->assertContains('UNREVIEWED', array_column($forOfficer['waiting']['items'], 'pv_no'));
        $this->assertNotContains('UNREVIEWED', array_column($forManager['waiting']['items'], 'pv_no'), 'never listed');
        $this->assertContains('RESUBMITTED', array_column($forManager['waiting']['items'], 'pv_no'));

        // The two are cached separately, so one cannot be served the other's list.
        $again = $analytics->report($b['aud'], ...$args);
        $this->assertContains('UNREVIEWED', array_column($again['waiting']['items'], 'pv_no'));
    }

    public function test_a_login_with_no_audit_right_reads_nothing_of_audit_and_an_officer_reads_what_is_theirs(): void
    {
        $b = $this->books();
        $financeOnly = $this->finance('F2', $this->sowutuom);

        $this->assertSame(0, $this->scope()->pvs($financeOnly, FinanceScope::AS_AUDIT)->count());
        $this->assertSame(0, $this->scope()->pvsForTotals($financeOnly, FinanceScope::AS_AUDIT)->count());
        $this->assertSame(FinanceScope::AUDIT_ASSIGNED, $this->scope()->auditReach($b['aud']));
        $this->assertSame(FinanceScope::AUDIT_ALL, $this->scope()->auditReach($this->superAdmin()));
        $this->assertSame(FinanceScope::AUDIT_WORKED, $this->scope()->auditReach($this->auditManager('AM1', $this->sowutuom)));
        $this->assertSame(FinanceScope::AUDIT_ASSIGNED, $this->scope()->auditReach($this->both('B1', $this->sowutuom)), 'a person who is also an Audit officer is an officer');
    }

    public function test_the_audit_menu_has_no_category_entry_and_the_manager_gets_the_review_list_and_reports(): void
    {
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $officer = $this->audit('AU1', $this->sowutuom);
        $navigation = app(\App\Support\ErpNavigation::class);

        $routes = fn ($user) => collect($navigation->build($user, 'audit')['sidebar'])->pluck('route')->filter()->values()->all();

        $this->assertEqualsCanonicalizing(['audit.assignments', 'audit.home', 'audit.batches', 'audit.reports', 'audit.reminder-settings'], $routes($manager));
        $this->assertEqualsCanonicalizing(['audit.home', 'audit.batches', 'audit.suppliers', 'audit.reports'], $routes($officer));

        $labels = fn ($user) => collect($navigation->build($user, 'audit')['sidebar'])->pluck('label')->filter()->all();
        $this->assertContains('Reviewed vouchers', $labels($manager));
        $this->assertContains('My work', $labels($officer));

        // The Audit tab opens the assignment queue for the manager, the list for everyone else.
        $this->assertSame('audit.assignments', $navigation->auditLandingRoute($manager));
        $this->assertSame('audit.home', $navigation->auditLandingRoute($officer));
        $this->assertSame('audit.home', $navigation->auditLandingRoute($this->superAdmin()));
    }
}
