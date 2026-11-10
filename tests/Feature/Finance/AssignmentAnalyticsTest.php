<?php

namespace Tests\Feature\Finance;

use App\Livewire\Finance\Reports;
use App\Livewire\Finance\ReminderSettings;
use App\Models\FinPaymentVoucher as Pv;
use App\Services\Finance\AuditSettings;
use App\Services\Finance\BatchWorkflowService;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvAnalyticsService;
use App\Services\Finance\PvBacklog;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Step J: the analytics of assignment, batches and officers; the manager-only view; the new Audit reminder setting. */
class AssignmentAnalyticsTest extends FinanceTestCase
{
    private function analytics(): PvAnalyticsService
    {
        return app(PvAnalyticsService::class);
    }

    private function west(): array
    {
        return ['region_id' => $this->sowutuom->region_id, 'district_id' => $this->sowutuom->id];
    }

    private function report($user, string $as): array
    {
        return $this->analytics()->report($user, $as, $this->west(), '2026-10-01', '2026-11-07');
    }

    private function at(string $time, callable $do): mixed
    {
        Carbon::setTestNow($time);

        return $do();
    }

    /** Two officers, a manager, three sent vouchers with known times. @return array<string, mixed> */
    private function books(): array
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $one = $this->audit('AU1', $this->sowutuom);
        $two = $this->audit('AU2', $this->sowutuom);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $send = fn (string $no, string $time) => $this->at($time, fn () => $this->sent($fin, ['pv_no' => $no, 'pv_date' => Carbon::parse($time)->subDay()->toDateString()]));

        // V1: sent 6 Oct 09:00, assigned 8 Oct 09:00 (2 days), queried 9 Oct 21:00 (1.5 days after the assignment).
        $v1 = $send('V1', '2026-10-06 09:00');
        $this->at('2026-10-08 09:00', fn () => $this->workflow()->assign($manager, $v1, $one->id));
        $this->at('2026-10-09 21:00', fn () => $this->workflow()->auditQuery($one, $v1, 'other', 'Check'));

        // V2: sent 10 Oct 09:00, assigned 3 hours later (0.125 days), ticked 12 Oct 12:00 (2 days after the assignment).
        $v2 = $send('V2', '2026-10-10 09:00');
        $this->at('2026-10-10 12:00', fn () => $this->workflow()->assign($manager, $v2, $one->id));
        $this->at('2026-10-12 12:00', fn () => $this->workflow()->auditAcknowledge($one, $v2));

        // V3: sent 3 Nov, still with nobody; V4: given to the second officer, still waiting for them.
        $send('V3', '2026-11-03 09:00');
        $v4 = $send('V4', '2026-11-04 09:00');
        $this->at('2026-11-04 10:00', fn () => $this->workflow()->assign($manager, $v4, $two->id));

        Carbon::setTestNow('2026-11-07 09:00:00');

        return compact('fin', 'one', 'two', 'manager');
    }

    public function test_the_assignment_figures_come_from_the_events_and_the_backlog(): void
    {
        ['manager' => $manager] = $this->books();

        $a = $this->report($manager, FinanceScope::AS_AUDIT)['assignment'];

        $this->assertSame(['days' => 0.7, 'count' => 3], $a['days_to_assign'], 'V1 2 days, V2 0.125, V4 1 hour: (2 + 0.125 + 0.042) / 3');
        $this->assertSame(['days' => 1.8, 'count' => 2], $a['days_to_first_action'], 'V1 1.5 days, V2 2 days');
        $this->assertSame(['count' => 1, 'overdue' => 1, 'oldest_days' => 4, 'threshold' => 1], $a['unassigned'], 'V3 has waited 4 days and the limit is 1');

        // The one definition of waiting: every voucher With Audit is either unassigned or an officer's.
        $items = app(PvBacklog::class)->auditItems(Pv::query());
        $this->assertSame(1, $items->where('kind', PvBacklog::UNASSIGNED)->count());
        $this->assertSame(1, $items->where('kind', PvBacklog::OFFICER)->count());
        $this->assertSame($items->count(), $this->report($manager, FinanceScope::AS_AUDIT)['waiting']['kinds']['audit']['count'], 'the Reports still count With Audit as one');
    }

    public function test_the_existing_figures_do_not_change_for_the_audit_manager_and_the_officer(): void
    {
        ['manager' => $manager, 'one' => $one] = $this->books();

        $forManager = $this->report($manager, FinanceScope::AS_AUDIT);
        $forOfficer = $this->report($one, FinanceScope::AS_AUDIT);

        foreach (['tiles', 'audit', 'statuses', 'reasons', 'categories', 'months', 'assignment', 'batches'] as $key) {
            $this->assertEquals($forOfficer[$key], $forManager[$key], $key);
        }

        $this->assertSame(4, $forManager['tiles']['vouchers']);
        $this->assertSame(1, $forManager['audit']['queried_vouchers']);
    }

    public function test_officer_workload_is_the_managers_view_only(): void
    {
        ['manager' => $manager, 'one' => $one, 'two' => $two, 'fin' => $fin] = $this->books();
        $financeManager = $this->financeManager('FM1', $this->sowutuom);

        $officers = collect($this->report($manager, FinanceScope::AS_AUDIT)['officers'])->keyBy('user_id');

        $this->assertSame(
            ['assigned' => 2, 'done' => 1, 'open' => 0, 'queried' => 1, 'query_rate' => 50.0, 'average_days' => 1.8],
            collect($officers[$one->id])->only(['assigned', 'done', 'open', 'queried', 'query_rate', 'average_days'])->all()
        );
        $this->assertSame(['assigned' => 1, 'done' => 0, 'open' => 1, 'queried' => 0, 'query_rate' => 0.0, 'average_days' => null], collect($officers[$two->id])->only(['assigned', 'done', 'open', 'queried', 'query_rate', 'average_days'])->all());
        $this->assertSame([$two->id, $one->id], array_column($this->report($manager, FinanceScope::AS_AUDIT)['officers'], 'user_id'), 'the officer with open work first');

        // The Finance manager reads them through Finance; nobody else does, and a viewer who may not is never served the cached copy.
        $this->assertCount(2, $this->report($financeManager, FinanceScope::AS_FINANCE)['officers']);
        $this->assertNull($this->report($one, FinanceScope::AS_AUDIT)['officers']);
        $this->assertNull($this->report($fin, FinanceScope::AS_FINANCE)['officers']);
        $this->assertNull($this->report($this->financeManager('FM2', $this->sowutuom), FinanceScope::AS_AUDIT)['officers'] ?? null, 'a Finance manager has no Audit reach at all');
        $this->assertNotNull($this->report($this->superAdmin(), FinanceScope::AS_AUDIT)['officers']);
    }

    public function test_batches_are_counted_by_state_and_size_inside_the_location(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $batches = app(BatchWorkflowService::class);
        $make = fn ($user, string $prefix, int $n) => array_map(fn ($i) => $this->draft($user, ['pv_no' => "{$prefix}-{$i}", 'pv_date' => '2026-08-30'])->id, range(1, $n));

        $this->at('2026-10-20 09:00', fn () => $batches->send($fin, $batches->create($fin, $make($fin, 'S', 3))));      // sent, nobody has them
        $this->at('2026-11-02 09:00', fn () => $batches->create($fin, $make($fin, 'D', 2)));                            // still a draft
        $this->at('2026-11-01 09:00', fn () => $batches->cancel($fin, $batches->create($fin, $make($fin, 'C', 1)), 'Wrong'));
        $this->at('2026-09-01 09:00', fn () => $batches->create($ash, $make($ash, 'K', 4)));                            // another location, and out of the period
        Carbon::setTestNow('2026-11-07 09:00:00');

        $finance = $this->report($fin, FinanceScope::AS_FINANCE)['batches'];
        $this->assertSame(3, $finance['count']);
        $this->assertSame(5, $finance['vouchers'], 'the cancelled batch is empty and not a size');
        $this->assertSame(2.5, $finance['average_vouchers']);
        $this->assertSame(3, $finance['largest']);
        $this->assertSame(['draft' => 1, 'awaiting_assignment' => 1, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 1], collect($finance['states'])->pluck('count', 'key')->all());

        $audit = $this->report($manager, FinanceScope::AS_AUDIT)['batches'];
        $this->assertSame(1, $audit['count'], 'Audit counts only sent batches');
        $this->assertSame(['awaiting_assignment', 'in_progress', 'completed'], array_column($audit['states'], 'key'), 'never a draft or a cancelled one');

        $this->assertSame(0, $this->analytics()->report($this->finance('A2', $this->kumasi), FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07')['batches']['count'], 'a location\'s figures need its own viewer');
    }

    public function test_the_reports_screen_shows_the_new_sections_and_the_officer_table_only_to_managers(): void
    {
        $this->withoutVite();
        ['manager' => $manager, 'one' => $one] = $this->books();
        $this->actingAs($manager);

        Livewire::actingAs($manager)->test(Reports::class, ['module' => 'audit'])
            ->assertSee('Assignment to Audit officers')->assertSee('Batches')->assertSee('Officer workload')->assertSee($one->full_name);
        Livewire::actingAs($one)->test(Reports::class, ['module' => 'audit'])
            ->assertSee('Assignment to Audit officers')->assertDontSee('Officer workload');
    }

    // ------------------------------------------------------------------ the new setting

    public function test_the_audit_set_gains_the_unassigned_limit_and_only_the_audit_manager_changes_it(): void
    {
        $manager = $this->auditManager('AM1', $this->sowutuom);
        $financeManager = $this->financeManager('FM1', $this->sowutuom);
        $settings = app(AuditSettings::class);

        $this->assertSame(1, $settings->value($this->west(), AuditSettings::UNASSIGNED_WAIT), 'a day by default');
        $settings->save($manager, [AuditSettings::UNASSIGNED_WAIT => 2], null);
        $this->assertSame(2, $settings->value($this->west(), AuditSettings::UNASSIGNED_WAIT));
        $this->assertSame(3, $settings->value($this->west(), AuditSettings::WAIT), 'the officer limit is separate');

        try {
            $settings->save($financeManager, [AuditSettings::UNASSIGNED_WAIT => 9], null);
            $this->fail('A Finance manager must not change the Audit set.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->withoutVite();
        $this->actingAs($manager)->get(route('audit.reminder-settings'))->assertOk()->assertSee('Remind the Audit manager after')->assertSee('Remind the officer after');
        $this->actingAs($financeManager)->get(route('finance.reminder-settings'))->assertOk()->assertDontSee('Remind the Audit manager after');
        $this->assertNotNull(ReminderSettings::class);
    }
}
