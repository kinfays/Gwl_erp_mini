<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Employee;
use App\Models\HsPpeEntitlement;
use App\Models\HsPpeIssue;
use App\Models\HsPpeType;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\HealthSafety\PpeComplianceService;

class PpeComplianceTest extends HealthSafetyTestCase
{
    private JobTitle $technician;

    private JobTitle $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gwl.hs_expiry_warning_days' => 60]);

        $this->technician = JobTitle::query()->firstOrCreate(['job_title_name' => 'Technician']);
        $this->clerk = JobTitle::query()->firstOrCreate(['job_title_name' => 'Clerk']);
    }

    private function compliance(): PpeComplianceService
    {
        return app(PpeComplianceService::class);
    }

    private function officerAtHeadOffice(): User
    {
        return $this->officer('200001', $this->accraWest, $this->headOffice);
    }

    private function entitle(JobTitle $title, HsPpeType $type, int $quantity = 1): void
    {
        HsPpeEntitlement::query()->create(['job_title_id' => $title->id, 'ppe_type_id' => $type->id, 'quantity' => $quantity]);
    }

    private function holds(Employee $employee, HsPpeType $type, ?int $dueInDays, int $quantity = 1, string $status = 'issued'): HsPpeIssue
    {
        return HsPpeIssue::query()->create([
            'employee_id' => $employee->id, 'ppe_type_id' => $type->id, 'quantity' => $quantity, 'issued_on' => today()->subYear(),
            'replace_due_on' => $dueInDays === null ? null : today()->addDays($dueInDays), 'status' => $status, 'is_historic' => true,
        ]);
    }

    private function staff(string $staffId, ?JobTitle $title = null, ?\App\Models\District $district = null): Employee
    {
        $employee = $this->userWithRoles($staffId, ['employee'], $district?->region, $district)->employee;

        if ($title) {
            $employee->update(['job_title_id' => $title->id]);
        }

        return $employee->fresh();
    }

    private function stateOf(Employee $employee, HsPpeType $type): ?string
    {
        return $this->compliance()->rows($this->officerAtHeadOffice(), ['search' => $employee->staff_id])->firstWhere('ppe_type_id', $type->id)['state'] ?? null;
    }

    // ---------------------------------------------------------------- the five states

    public function test_each_state_is_reached_by_the_right_fixture(): void
    {
        $boots = $this->bootsType(['name' => 'Boots']);
        $this->entitle($this->technician, $boots, 1);

        $missing = $this->staff('300001', $this->technician);
        $overdue = $this->staff('300002', $this->technician);
        $short = $this->staff('300003', $this->technician);
        $dueSoon = $this->staff('300004', $this->technician);
        $fine = $this->staff('300005', $this->technician);

        $this->holds($overdue, $boots, -10);
        // short: entitled to two, holds one in date
        HsPpeEntitlement::query()->where('job_title_id', $this->technician->id)->update(['quantity' => 2]);
        $this->holds($short, $boots, 400);
        $this->holds($dueSoon, $boots, 400, 2);
        $this->holds($dueSoon, $boots, 30, 1);   // a third item, in date but within the window
        $this->holds($fine, $boots, 400, 2);

        $this->assertSame('missing', $this->stateOf($missing, $boots));
        $this->assertSame('overdue', $this->stateOf($overdue, $boots));
        $this->assertSame('short', $this->stateOf($short, $boots));
        $this->assertSame('replacement_due', $this->stateOf($dueSoon, $boots), 'covered, but the earliest in-date replacement date is inside the window (design 8.9)');
        $this->assertSame('ok', $this->stateOf($fine, $boots));

        // Now the pair that are due soon: entitled to 2, holds 2 but one is inside the window.
        $soon = $this->staff('300007', $this->technician);
        $this->holds($soon, $boots, 400, 1);
        $this->holds($soon, $boots, 30, 1);
        $this->assertSame('replacement_due', $this->stateOf($soon, $boots));
    }

    public function test_the_numbers_behind_a_row_are_entitled_held_in_date_and_next_due(): void
    {
        $boots = $this->bootsType(['name' => 'Boots']);
        $this->entitle($this->technician, $boots, 2);
        $kofi = $this->staff('300001', $this->technician);
        $this->holds($kofi, $boots, 200, 1);
        $this->holds($kofi, $boots, -5, 1);
        $this->holds($kofi, $boots, 40, 1);

        $row = $this->compliance()->rows($this->officerAtHeadOffice())->firstWhere('employee_id', $kofi->id);

        $this->assertSame(2, $row['entitled']);
        $this->assertSame(3, $row['held']);
        $this->assertSame(2, $row['in_date']);
        $this->assertSame(today()->subDays(5)->toDateString(), $row['next_due']->toDateString(), 'the earliest date, even a past one');
        $this->assertSame('replacement_due', $row['state'], 'two in date covers two, and the earliest in-date date is inside the window');
    }

    public function test_the_states_follow_their_precedence(): void
    {
        $boots = $this->bootsType(['name' => 'Boots']);
        $this->entitle($this->technician, $boots, 2);
        $service = $this->compliance();

        $row = fn (array $dues) => $service->evaluate(2, collect($dues)->map(fn ($due) => new HsPpeIssue([
            'quantity' => 1, 'replace_due_on' => $due === null ? null : today()->addDays($due),
        ]))->values());

        $this->assertSame('missing', $row([])['state']);
        $this->assertSame('overdue', $row([-5, -9])['state'], 'something issued, none of it in date');
        $this->assertSame('short', $row([-5, 200])['state'], 'one in date of two: short, the overdue row does not make it overdue');
        $this->assertSame('short', $row([200])['state'], 'one in date of two, nothing overdue');
        $this->assertSame('replacement_due', $row([200, 10])['state']);
        $this->assertSame('ok', $row([200, null])['state']);
        $this->assertSame('ok', $row([null, null])['state']);
        $this->assertSame('ok', $row([200, 200, -9])['state'], 'a stale open row beside enough in-date cover does not make someone overdue');
        $this->assertSame('overdue', $row([-3])['state'], 'a lone overdue row is overdue, not missing');
    }

    public function test_closed_issues_do_not_count_as_held(): void
    {
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->entitle($this->technician, $hat);
        $kofi = $this->staff('300001', $this->technician);

        foreach (['returned', 'worn_out', 'damaged', 'lost'] as $status) {
            $this->holds($kofi, $hat, 400, 1, $status);
        }

        $this->assertSame('missing', $this->stateOf($kofi, $hat));
    }

    public function test_an_entitlement_of_several_is_met_by_several_rows(): void
    {
        $gloves = $this->ppeType(['name' => 'Gloves']);
        $this->entitle($this->technician, $gloves, 3);
        $kofi = $this->staff('300001', $this->technician);

        $this->holds($kofi, $gloves, null, 1);
        $this->assertSame('short', $this->stateOf($kofi, $gloves));
        $this->holds($kofi, $gloves, null, 2);
        $this->assertSame('ok', $this->stateOf($kofi, $gloves));
    }

    // ---------------------------------------------------------------- who is evaluated

    public function test_job_titles_without_entitlements_are_not_evaluated_and_are_counted(): void
    {
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->entitle($this->technician, $hat);
        $this->staff('300001', $this->technician);
        $this->staff('300002', $this->clerk);
        $this->staff('300003', $this->clerk);

        $rows = $this->compliance()->rows($this->officerAtHeadOffice());

        $this->assertSame(['300001'], $rows->pluck('staff_id')->unique()->values()->all(), 'the clerks have no entitlements: not evaluated');
        $summary = $this->compliance()->summary($this->officerAtHeadOffice());
        $this->assertSame(2, $summary['titles_without_entitlements'], 'two job titles (Clerk, and General Staff for the officer looking), not three people');
    }

    public function test_inactive_employees_and_inactive_types_are_excluded(): void
    {
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $retired = $this->ppeType(['name' => 'Old item', 'is_active' => false]);
        $this->entitle($this->technician, $hat);
        $this->entitle($this->technician, $retired);
        $left = $this->staff('300001', $this->technician);
        $left->update(['is_active' => false]);
        $here = $this->staff('300002', $this->technician);

        $rows = $this->compliance()->rows($this->officerAtHeadOffice());

        $this->assertSame(['300002'], $rows->pluck('staff_id')->unique()->all());
        $this->assertSame(['Hard hat'], $rows->pluck('type')->unique()->all());
        $this->assertSame([], $this->compliance()->rowsForEmployee($left->fresh())->all());
        $this->assertNotNull($here);
    }

    public function test_an_entitlement_row_needs_a_quantity_of_at_least_one(): void
    {
        $hat = $this->ppeType(['name' => 'Hard hat']);
        HsPpeEntitlement::query()->create(['job_title_id' => $this->technician->id, 'ppe_type_id' => $hat->id, 'quantity' => 0]);
        $this->staff('300001', $this->technician);

        $this->assertSame(0, $this->compliance()->rows($this->officerAtHeadOffice())->count());
    }

    // ---------------------------------------------------------------- scope

    public function test_the_evaluator_only_looks_at_staff_in_the_actors_scope(): void
    {
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->entitle($this->technician, $hat);
        $accra = $this->staff('300001', $this->technician, $this->odorkor);
        $kumasi = $this->staff('300002', $this->technician, $this->kumasi);
        $sowutuom = $this->staff('300003', $this->technician, $this->sowutuom);

        $regional = $this->officer('200010', $this->accraWest, $this->odorkor);
        $this->assertEqualsCanonicalizing(['300001', '300003'], $this->compliance()->rows($regional)->pluck('staff_id')->all());

        $manager = $this->districtManager('200003', $this->sowutuom);
        $this->assertSame(['300003'], $this->compliance()->rows($manager)->pluck('staff_id')->all());

        $this->assertEqualsCanonicalizing(['300001', '300002', '300003'], $this->compliance()->rows($this->hsManager())->pluck('staff_id')->all());
        $this->assertNotNull($accra && $kumasi && $sowutuom);
    }

    // ---------------------------------------------------------------- filters

    public function test_filters_by_state_gap_district_job_title_type_and_search(): void
    {
        $boots = $this->bootsType(['name' => 'Boots']);
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->entitle($this->technician, $boots);
        $this->entitle($this->technician, $hat);
        $this->entitle($this->clerk, $hat);

        $kofi = $this->staff('300001', $this->technician, $this->sowutuom);
        $ama = $this->staff('300002', $this->clerk, $this->odorkor);
        $this->holds($kofi, $boots, 400);
        $this->holds($kofi, $hat, 10);

        $officer = $this->officerAtHeadOffice();
        $rows = fn (array $filters) => $this->compliance()->rows($officer, $filters);

        $this->assertSame(['replacement_due'], $rows(['search' => '300001', 'type_id' => $hat->id])->pluck('state')->all());
        $this->assertSame(['ok', 'replacement_due'], $rows(['search' => 'Staff 300001'])->pluck('state')->sort()->values()->all());
        $this->assertSame(['300002'], $rows(['state' => 'gap'])->pluck('staff_id')->all(), 'only Ama has a gap (missing a hat)');
        $this->assertSame(['300001', '300001'], $rows(['district_id' => $this->sowutuom->id])->pluck('staff_id')->all());
        $this->assertSame(['300002'], $rows(['job_title_id' => $this->clerk->id])->pluck('staff_id')->all());
        $this->assertSame('missing', $rows(['state' => 'missing'])->first()['state']);
        $this->assertNotNull($ama);
    }

    public function test_the_order_is_worst_first_then_by_name(): void
    {
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->entitle($this->technician, $hat);
        $a = $this->staff('300001', $this->technician);
        $b = $this->staff('300002', $this->technician);
        $c = $this->staff('300003', $this->technician);
        $this->holds($a, $hat, 400);
        $this->holds($c, $hat, -2);

        $states = $this->compliance()->rows($this->officerAtHeadOffice())->pluck('state')->all();

        $this->assertSame(['missing', 'overdue', 'ok'], $states);
        $this->assertNotNull($b);
    }

    public function test_pagination_slices_the_result_without_re_evaluating(): void
    {
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->entitle($this->technician, $hat);

        foreach (range(1, 7) as $n) {
            $this->staff('3001'.$n, $this->technician);
        }

        $rows = $this->compliance()->rows($this->officerAtHeadOffice());
        $page = $this->compliance()->paginate($rows, 3, 2, '/x');

        $this->assertSame(7, $page->total());
        $this->assertCount(3, $page->items());
        $this->assertSame(3, $page->lastPage());
        $this->assertSame($rows->slice(3, 3)->pluck('staff_id')->values()->all(), collect($page->items())->pluck('staff_id')->all());
    }

    // ---------------------------------------------------------------- one evaluator for all three screens

    public function test_the_summary_counts_are_exactly_what_the_listing_returns(): void
    {
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $boots = $this->ppeType(['name' => 'Boots']);
        $this->entitle($this->technician, $hat);
        $this->entitle($this->technician, $boots);

        $a = $this->staff('300001', $this->technician);     // missing both
        $b = $this->staff('300002', $this->technician);     // overdue hat, missing boots
        $c = $this->staff('300003', $this->technician);     // ok hat, replacement due boots
        $d = $this->staff('300004', $this->technician);     // ok both
        $this->holds($b, $hat, -3);
        $this->holds($c, $hat, 400);
        $this->holds($c, $boots, 20);
        $this->holds($d, $hat, null);
        $this->holds($d, $boots, 400);

        $officer = $this->officerAtHeadOffice();
        $summary = $this->compliance()->summary($officer);
        $rows = $this->compliance()->rows($officer);
        $gaps = $this->compliance()->rows($officer, ['state' => 'gap']);

        $this->assertSame(8, $summary['rows']);
        $this->assertSame(['missing' => 3, 'overdue' => 1, 'short' => 0, 'replacement_due' => 1, 'ok' => 3], $summary['by_state']);
        $this->assertSame($gaps->count(), $summary['gap_rows']);
        $this->assertSame(4, $summary['gap_rows']);
        $this->assertSame(2, $summary['gap_employees'], 'distinct people: a and b');
        $this->assertSame($gaps->pluck('employee_id')->unique()->count(), $summary['gap_employees']);
        $this->assertSame($rows->count(), array_sum($summary['by_state']));

        // My PPE gives the same states for the same person.
        foreach ([$a, $b, $c, $d] as $person) {
            $mine = $this->compliance()->rowsForEmployee($person)->pluck('state', 'type')->all();
            $theirs = $rows->where('employee_id', $person->id)->pluck('state', 'type')->all();
            $this->assertSame($theirs, $mine, "{$person->staff_id}: My PPE agrees with the gaps screen");
        }
    }
}
