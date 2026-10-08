<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\HsPpeIssue;
use App\Models\HsPpeStockMovement;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetySettings;
use App\Services\HealthSafety\PpeIssueService;
use App\Services\HealthSafety\PpeStockService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PpeIssueTest extends HealthSafetyTestCase
{
    private function issues(): PpeIssueService
    {
        return app(PpeIssueService::class);
    }

    private function stock(): PpeStockService
    {
        return app(PpeStockService::class);
    }

    private function officer0(): User
    {
        return $this->officer('200001', $this->accraWest, $this->headOffice);
    }

    private function staffMember(string $staffId = '300001'): User
    {
        return $this->userWithRoles($staffId, ['employee']);
    }

    private function data(array $overrides = []): array
    {
        return ['quantity' => 1, ...$overrides];
    }

    // ---------------------------------------------------------------- posting the ledger row

    public function test_an_issue_takes_stock_from_the_store_in_the_same_transaction(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staffMember();
        $this->stocked($store, $hat, 5);

        $issue = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id, 'quantity' => 2]));

        $this->assertSame(HsPpeIssue::STATUS_ISSUED, $issue->status);
        $this->assertSame(today()->toDateString(), $issue->issued_on->toDateString());
        $this->assertSame($officer->id, $issue->issued_by);
        $this->assertFalse($issue->is_historic);
        $this->assertSame(3, $this->stock()->balance($store, $hat));

        $line = HsPpeStockMovement::query()->where('issue_id', $issue->id)->firstOrFail();
        $this->assertSame(['issue', -2, $store->id], [$line->movement_type, $line->quantity, $line->site_id]);
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.ppe_issued')->where('target_id', $issue->id)->exists());
    }

    public function test_an_issue_the_store_cannot_cover_is_refused_and_leaves_no_issue_behind(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staffMember();
        $this->stocked($store, $hat, 1);

        try {
            $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id, 'quantity' => 2]));
            $this->fail('Only one in stock.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('below zero', $exception->errors()['quantity'][0]);
        }

        $this->assertSame(0, HsPpeIssue::query()->count());
        $this->assertSame(1, $this->stock()->balance($store, $hat));
    }

    // ---------------------------------------------------------------- the replacement date

    public function test_the_replacement_date_is_the_earlier_of_the_service_life_and_the_expiry(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $kofi = $this->staffMember();

        $life = $this->ppeType(['name' => 'Boots', 'replacement_months' => 12]);
        $filter = $this->ppeType(['name' => 'Filter', 'replacement_months' => 12, 'has_expiry' => true]);
        $mask = $this->ppeType(['name' => 'Mask', 'replacement_months' => null, 'has_expiry' => true]);
        $glasses = $this->ppeType(['name' => 'Glasses', 'replacement_months' => null, 'has_expiry' => false]);

        foreach ([$life, $filter, $mask, $glasses] as $type) {
            $this->stocked($store, $type, 5);
        }

        $base = ['employee_id' => $kofi->employee->id, 'store_id' => $store->id];

        // From the service life alone.
        $byLife = $this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $life->id]));
        $this->assertSame(today()->addMonths(12)->toDateString(), $byLife->replace_due_on->toDateString());

        // The expiry is sooner than the service life: it wins.
        $sooner = $this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $filter->id, 'expires_on' => today()->addMonths(5)->toDateString()]));
        $this->assertSame(today()->addMonths(5)->toDateString(), $sooner->replace_due_on->toDateString());

        // The service life is sooner than the expiry: it wins.
        $later = $this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $filter->id, 'expires_on' => today()->addMonths(30)->toDateString()]));
        $this->assertSame(today()->addMonths(12)->toDateString(), $later->replace_due_on->toDateString());

        // The expiry alone.
        $byExpiry = $this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $mask->id, 'expires_on' => today()->addMonths(7)->toDateString()]));
        $this->assertSame(today()->addMonths(7)->toDateString(), $byExpiry->replace_due_on->toDateString());

        // Neither: no date, and the issue is never flagged.
        $never = $this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $glasses->id]));
        $this->assertNull($never->replace_due_on);
        $this->assertSame('ok', $never->state());

        $this->travelTo(today()->addYears(20));
        $this->assertSame('ok', $never->fresh()->state(), 'twenty years on, an issue with no date is still not flagged');
        $this->assertSame(0, HsPpeIssue::query()->whereKey($never->id)->overdue()->count());
    }

    public function test_the_issue_state_is_overdue_replacement_due_or_ok_and_only_for_open_issues(): void
    {
        config(['gwl.hs_expiry_warning_days' => 60]);
        $kofi = $this->staffMember();
        $type = $this->ppeType(['name' => 'Boots']);

        $issue = fn (?int $dueInDays, string $status = 'issued') => HsPpeIssue::query()->create([
            'employee_id' => $kofi->employee->id, 'ppe_type_id' => $type->id, 'quantity' => 1, 'issued_on' => today()->subYear(),
            'replace_due_on' => $dueInDays === null ? null : today()->addDays($dueInDays), 'status' => $status,
        ]);

        $overdue = $issue(-1);
        $today = $issue(0);
        $edge = $issue(60);
        $beyond = $issue(61);
        $none = $issue(null);
        $closed = $issue(-100, 'lost');

        $this->assertSame('overdue', $overdue->state());
        $this->assertSame('replacement_due', $today->state(), 'due today is not yet overdue');
        $this->assertSame('replacement_due', $edge->state());
        $this->assertSame('ok', $beyond->state());
        $this->assertSame('ok', $none->state());
        $this->assertNull($closed->state());

        // The query scopes give the same answers as the accessor.
        foreach (['overdue', 'replacement_due', 'ok'] as $state) {
            $fromSql = HsPpeIssue::query()->withState($state)->pluck('id')->sort()->values()->all();
            $fromPhp = HsPpeIssue::query()->get()->filter(fn ($row) => $row->state() === $state)->pluck('id')->sort()->values()->all();
            $this->assertSame($fromPhp, $fromSql, $state);
        }
    }

    // ---------------------------------------------------------------- sizes and expiry

    public function test_size_is_required_exactly_when_the_type_has_sizes_and_must_be_in_the_list(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $kofi = $this->staffMember();
        $boots = $this->bootsType();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->stocked($store, $boots, 5, '41');
        $this->stocked($store, $hat, 5);
        $base = ['employee_id' => $kofi->employee->id, 'store_id' => $store->id];

        foreach ([null, '', '50'] as $bad) {
            try {
                $this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $boots->id, 'size' => $bad]));
                $this->fail('A sized type needs a listed size.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('size', $exception->errors());
            }
        }

        try {
            $this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $hat->id, 'size' => 'L']));
            $this->fail('A type without sizes takes none.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('size', $exception->errors());
        }

        $this->assertSame(0, HsPpeIssue::query()->count());
        $this->assertSame('41', $this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $boots->id, 'size' => '41']))->size);
        $this->assertNull($this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $hat->id]))->size);
    }

    public function test_expiry_is_required_exactly_when_the_type_has_an_expiry(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $kofi = $this->staffMember();
        $filter = $this->ppeType(['name' => 'Filter', 'has_expiry' => true]);
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->stocked($store, $filter, 5);
        $this->stocked($store, $hat, 5);
        $base = ['employee_id' => $kofi->employee->id, 'store_id' => $store->id];

        try {
            $this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $filter->id]));
            $this->fail('A type with an expiry needs the date.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expires_on', $exception->errors());
        }

        try {
            $this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $filter->id, 'expires_on' => today()->subDay()->toDateString()]));
            $this->fail('It cannot expire before it is issued.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expires_on', $exception->errors());
        }

        // A type without an expiry ignores a date it is given.
        $this->assertNull($this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $hat->id, 'expires_on' => today()->addYear()->toDateString()]))->expires_on);
        $this->assertSame(today()->addYear()->toDateString(), $this->issues()->issue($officer, $this->data([...$base, 'ppe_type_id' => $filter->id, 'expires_on' => today()->addYear()->toDateString()]))->expires_on->toDateString());
    }

    public function test_quantity_must_be_at_least_one(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->stocked($store, $hat, 5);

        foreach ([0, -1, 'x', 1.5] as $bad) {
            try {
                $this->issues()->issue($officer, $this->data(['employee_id' => $this->staffMember()->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id, 'quantity' => $bad]));
                $this->fail("{$bad} must be refused");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('quantity', $exception->errors());
            }
        }
    }

    // ---------------------------------------------------------------- already held (historic)

    public function test_an_already_held_issue_posts_no_stock_needs_no_store_and_may_be_back_dated(): void
    {
        $officer = $this->officer0();
        $kofi = $this->staffMember();
        $boots = $this->bootsType(['replacement_months' => 24]);

        $issue = $this->issues()->issue($officer, $this->data([
            'employee_id' => $kofi->employee->id, 'ppe_type_id' => $boots->id, 'size' => '42', 'is_historic' => true, 'issued_on' => '2024-02-10',
        ]));

        $this->assertTrue($issue->is_historic);
        $this->assertSame('2024-02-10', $issue->issued_on->toDateString());
        $this->assertSame('2026-02-10', $issue->replace_due_on->toDateString());
        $this->assertSame(0, HsPpeStockMovement::query()->count(), 'nothing was taken from any store');

        // A future date is still refused, and so is a missing one.
        foreach ([today()->addDay()->toDateString(), null] as $bad) {
            try {
                $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $boots->id, 'size' => '42', 'is_historic' => true, 'issued_on' => $bad]));
                $this->fail('A historic issue needs a past or present date.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('issued_on', $exception->errors());
            }
        }
    }

    public function test_a_normal_issue_may_be_dated_back_within_the_window_but_not_beyond_it_or_into_the_future(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat', 'replacement_months' => 6]);
        $this->stocked($store, $hat, 10);
        $base = ['employee_id' => $this->staffMember()->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id];

        // Design 8.16 item 4: up to hs_issue_backdate_days (7) earlier is accepted, today included.
        foreach ([0, 5, 7] as $daysBack) {
            $date = today()->subDays($daysBack);
            $issue = $this->issues()->issue($officer, $this->data([...$base, 'issued_on' => $date->toDateString()]));

            $this->assertSame($date->toDateString(), $issue->issued_on->toDateString());
            $this->assertSame($date->copy()->addMonths(6)->toDateString(), $issue->replace_due_on->toDateString(), 'the replacement date is counted from the given date');
            $this->assertSame($date->toDateString(), HsPpeStockMovement::query()->where('issue_id', $issue->id)->firstOrFail()->occurred_on->toDateString(), 'the ledger row carries that date');
        }

        $before = HsPpeIssue::query()->count();

        foreach ([today()->subDays(8)->toDateString(), today()->addDay()->toDateString()] as $bad) {
            try {
                $this->issues()->issue($officer, $this->data([...$base, 'issued_on' => $bad]));
                $this->fail('A date beyond the window, or in the future, must be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('issued_on', $exception->errors());
            }
        }

        $this->assertSame($before, HsPpeIssue::query()->count());
        $this->assertSame(7, $this->stock()->balance($store, $hat), 'a refused issue takes nothing out');
    }

    public function test_the_backdating_window_follows_the_setting(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->stocked($store, $hat, 5);
        $base = ['employee_id' => $this->staffMember()->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id];

        app(HealthSafetySettings::class)->save(['hs_issue_backdate_days' => 0], $officer->id);

        try {
            $this->issues()->issue($officer, $this->data([...$base, 'issued_on' => today()->subDay()->toDateString()]));
            $this->fail('With a window of 0 only today is accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('issued_on', $exception->errors());
        }

        app(HealthSafetySettings::class)->save(['hs_issue_backdate_days' => 30], $officer->id);

        $this->assertSame(today()->subDays(30)->toDateString(), $this->issues()->issue($officer, $this->data([...$base, 'issued_on' => today()->subDays(30)->toDateString()]))->issued_on->toDateString());
    }

    public function test_a_close_may_be_dated_back_within_the_window_and_posts_that_date(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->stocked($store, $hat, 5);
        $kofi = $this->staffMember();

        $issue = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id, 'issued_on' => today()->subDays(7)->toDateString()]));

        foreach ([today()->subDays(8)->toDateString(), today()->addDay()->toDateString()] as $bad) {
            try {
                $this->issues()->close($officer, $issue, 'returned', $bad, null, $store);
                $this->fail('A close beyond the window, or in the future, must be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('closed_on', $exception->errors());
            }
        }

        $closed = $this->issues()->close($officer, $issue, 'returned', today()->subDays(3)->toDateString(), null, $store);

        $this->assertSame(today()->subDays(3)->toDateString(), $closed->closed_on->toDateString());
        $this->assertSame(today()->subDays(3)->toDateString(), HsPpeStockMovement::query()->where('issue_id', $issue->id)->where('movement_type', 'return')->firstOrFail()->occurred_on->toDateString());
    }

    public function test_an_already_held_row_still_takes_any_past_date_and_a_close_after_its_issue_date(): void
    {
        $officer = $this->officer0();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staffMember();

        $issue = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'is_historic' => true, 'issued_on' => today()->subYears(2)->toDateString()]));
        $this->assertSame(today()->subYears(2)->toDateString(), $issue->issued_on->toDateString());

        $closed = $this->issues()->close($officer, $issue, 'worn_out', today()->subMonths(5)->toDateString());
        $this->assertSame(today()->subMonths(5)->toDateString(), $closed->closed_on->toDateString(), 'a row that never came from a store is not held to the window');
    }

    public function test_a_normal_issue_needs_a_ppe_store_in_scope(): void
    {
        $officer = $this->officer('200001', $this->accraWest, $this->odorkor);
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staffMember();
        $plain = $this->site('Plain office');
        $kumasi = $this->ppeStore('Kumasi store', $this->kumasi);

        try {
            $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id]));
            $this->fail('A store is needed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('store_id', $exception->errors());
        }

        try {
            $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $plain->id]));
            $this->fail('A plain site is not a store.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('store_id', $exception->errors());
        }

        $this->expectException(HttpException::class);
        $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $kumasi->id]));
    }

    // ---------------------------------------------------------------- closing

    public function test_closing_as_returned_puts_the_stock_back_into_the_chosen_store(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $returnsTo = $this->ppeStore('Returns store');
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staffMember();
        $this->stocked($store, $hat, 5);
        $issue = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id, 'quantity' => 2]));

        // A returned item needs a store to go back to.
        try {
            $this->issues()->close($officer, $issue, 'returned');
            $this->fail('A store is required for a return.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('store_id', $exception->errors());
        }

        $closed = $this->issues()->close($officer, $issue, 'returned', null, 'Handed back at leaving', $returnsTo);

        $this->assertSame('returned', $closed->status);
        $this->assertSame(today()->toDateString(), $closed->closed_on->toDateString());
        $this->assertSame('Handed back at leaving', $closed->close_note);
        $line = HsPpeStockMovement::query()->where('issue_id', $issue->id)->where('movement_type', 'return')->firstOrFail();
        $this->assertSame([2, $returnsTo->id], [$line->quantity, $line->site_id]);
        $this->assertSame(2, $this->stock()->balance($returnsTo, $hat));
        $this->assertSame(3, $this->stock()->balance($store, $hat));
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.ppe_issue_closed')->exists());
    }

    public function test_worn_out_damaged_and_lost_post_nothing(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->stocked($store, $hat, 10);

        foreach (['worn_out', 'damaged', 'lost'] as $outcome) {
            $issue = $this->issues()->issue($officer, $this->data(['employee_id' => $this->staffMember('3000'.strlen($outcome))->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id]));
            $before = HsPpeStockMovement::query()->count();

            $closed = $this->issues()->close($officer, $issue, $outcome);

            $this->assertSame($outcome, $closed->status);
            $this->assertSame($before, HsPpeStockMovement::query()->count(), "{$outcome} posts no ledger line");
        }
    }

    public function test_returning_a_historic_issue_needs_no_store_and_posts_no_stock(): void
    {
        $officer = $this->officer0();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $issue = $this->issues()->issue($officer, $this->data(['employee_id' => $this->staffMember()->employee->id, 'ppe_type_id' => $hat->id, 'is_historic' => true, 'issued_on' => '2023-05-01']));

        $closed = $this->issues()->close($officer, $issue, 'returned');

        $this->assertSame('returned', $closed->status);
        $this->assertSame(0, HsPpeStockMovement::query()->count(), 'it never came out of a store, so nothing goes back');
    }

    public function test_an_issue_closes_only_once_and_a_closed_issue_cannot_be_changed(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->stocked($store, $hat, 5);
        $issue = $this->issues()->issue($officer, $this->data(['employee_id' => $this->staffMember()->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id]));
        $this->issues()->close($officer, $issue, 'lost');

        foreach (['returned', 'lost', 'worn_out'] as $again) {
            try {
                $this->issues()->close($officer, $issue, $again, null, null, $store);
                $this->fail('A second close must be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
        }

        $this->assertSame(0, HsPpeStockMovement::query()->where('movement_type', 'return')->count(), 'no stock came back from the refused returns');

        // The service has no way to edit an issue at all.
        foreach (['update', 'edit', 'reopen', 'delete', 'void'] as $method) {
            $this->assertFalse(method_exists($this->issues(), $method), "PpeIssueService has no {$method}");
        }

        // And a closed one cannot be confirmed either.
        $this->expectException(ValidationException::class);
        $this->issues()->acknowledge($this->userWithRoles('300001', ['employee']), $issue);
    }

    public function test_an_outcome_must_be_one_of_the_four(): void
    {
        $officer = $this->officer0();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $issue = $this->issues()->issue($officer, $this->data(['employee_id' => $this->staffMember()->employee->id, 'ppe_type_id' => $hat->id, 'is_historic' => true, 'issued_on' => '2023-05-01']));

        $this->expectException(ValidationException::class);
        $this->issues()->close($officer, $issue, 'sold');
    }

    // ---------------------------------------------------------------- the replacement step

    public function test_the_replacement_step_closes_the_old_rows_and_links_them_in_one_transaction(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $boots = $this->bootsType();
        $kofi = $this->staffMember();
        $this->stocked($store, $boots, 5, '41');

        $old = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $boots->id, 'size' => '41', 'is_historic' => true, 'issued_on' => '2024-01-10']));
        $older = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $boots->id, 'size' => '41', 'is_historic' => true, 'issued_on' => '2023-01-10']));

        $new = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $boots->id, 'size' => '41', 'store_id' => $store->id]), [
            ['issue_id' => $old->id, 'outcome' => 'worn_out', 'note' => 'Soles gone'],
            ['issue_id' => $older->id, 'outcome' => 'damaged'],
        ]);

        foreach ([$old, $older] as $row) {
            $row = $row->fresh();
            $this->assertNotSame('issued', $row->status);
            $this->assertSame($new->id, $row->replaced_by_issue_id);
            $this->assertSame(today()->toDateString(), $row->closed_on->toDateString());
        }

        $this->assertSame('worn_out', $old->fresh()->status);
        $this->assertSame('Soles gone', $old->fresh()->close_note);
        $this->assertSame('issued', $new->fresh()->status);
        $this->assertSame(4, $this->stock()->balance($store, $boots, '41'));
    }

    public function test_a_returned_row_in_the_replacement_goes_back_to_the_chosen_store(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $returns = $this->ppeStore('Returns');
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staffMember();
        $this->stocked($store, $hat, 5);
        $old = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id]));

        $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id]), [
            ['issue_id' => $old->id, 'outcome' => 'returned', 'store_id' => $returns->id],
        ]);

        $this->assertSame(1, $this->stock()->balance($returns, $hat));
        $this->assertSame(3, $this->stock()->balance($store, $hat));   // 5 - 2 issued
    }

    public function test_the_replacement_rolls_back_entirely_if_the_new_issue_fails(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staffMember();
        $old = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'is_historic' => true, 'issued_on' => '2023-05-01']));

        // The store is empty, so the new issue fails; the old row must stay open.
        try {
            $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id]), [['issue_id' => $old->id, 'outcome' => 'worn_out']]);
            $this->fail('No stock.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('issued', $old->fresh()->status);
        $this->assertNull($old->fresh()->replaced_by_issue_id);
        $this->assertSame(1, HsPpeIssue::query()->count(), 'no new issue survived');
    }

    public function test_the_replacement_rolls_back_entirely_if_a_closing_fails(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staffMember();
        $this->stocked($store, $hat, 5);
        $good = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'is_historic' => true, 'issued_on' => '2023-05-01']));
        $balance = $this->stock()->balance($store, $hat);

        try {
            // The second closing is "returned" with no store: the whole step, including the new issue, must undo.
            $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id]), [
                ['issue_id' => $good->id, 'outcome' => 'worn_out'],
                ['issue_id' => $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id]))->id, 'outcome' => 'returned'],
            ]);
            $this->fail('A return with no store must fail.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('issued', $good->fresh()->status, 'the first closing was undone with the rest');
        $this->assertSame($balance - 1, $this->stock()->balance($store, $hat), 'only the unrelated issue made before the step took stock');
    }

    public function test_the_replacement_only_accepts_the_same_persons_open_rows_of_the_same_type(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $boots = $this->ppeType(['name' => 'Boots']);
        $kofi = $this->staffMember();
        $ama = $this->staffMember('300002');
        $this->stocked($store, $hat, 5);
        $amas = $this->issues()->issue($officer, $this->data(['employee_id' => $ama->employee->id, 'ppe_type_id' => $hat->id, 'is_historic' => true, 'issued_on' => '2023-05-01']));
        $kofisBoots = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $boots->id, 'is_historic' => true, 'issued_on' => '2023-05-01']));
        $closed = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'is_historic' => true, 'issued_on' => '2023-05-01']));
        $this->issues()->close($officer, $closed, 'lost');

        foreach ([$amas, $kofisBoots, $closed] as $wrong) {
            try {
                $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id]), [['issue_id' => $wrong->id, 'outcome' => 'worn_out']]);
                $this->fail('That row may not be replaced here.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertSame('issued', $amas->fresh()->status);
        $this->assertSame(5, $this->stock()->balance($store, $hat), 'every refused attempt rolled its stock line back');
    }

    // ---------------------------------------------------------------- confirming receipt

    public function test_only_the_employee_can_confirm_receipt_and_only_once(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staffMember('300001');
        $ama = $this->staffMember('300002');
        $this->stocked($store, $hat, 5);
        $issue = $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id]));

        foreach ([$ama, $officer] as $other) {
            try {
                $this->issues()->acknowledge($other, $issue);
                $this->fail('Only Kofi can confirm his own PPE.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertNull($issue->fresh()->acknowledged_at);

        $confirmed = $this->issues()->acknowledge($kofi, $issue);
        $this->assertNotNull($confirmed->acknowledged_at);
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.ppe_acknowledged')->exists());

        $this->expectException(ValidationException::class);
        $this->issues()->acknowledge($kofi, $issue);
    }

    // ---------------------------------------------------------------- permissions and scope

    public function test_issuing_and_closing_need_manage_ppe_and_the_employee_in_scope(): void
    {
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staffMember();
        $this->stocked($store, $hat, 5);
        $data = $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'store_id' => $store->id]);

        foreach ([$this->districtManager('200003', $this->headOffice), $this->reporter('100050')] as $user) {
            try {
                $this->issues()->issue($user, $data);
                $this->fail('No manage_ppe.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        // An officer in another region may not issue to Kofi (Accra West), nor close his issue.
        $kumasiOfficer = $this->officer('200050', $this->ashanti, $this->kumasi);
        $issue = $this->issues()->issue($this->officer0(), $data);

        foreach ([fn () => $this->issues()->issue($kumasiOfficer, $data), fn () => $this->issues()->close($kumasiOfficer, $issue, 'lost')] as $attempt) {
            try {
                $attempt();
                $this->fail('Another region must be refused.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertSame('issued', $issue->fresh()->status);
    }

    public function test_an_inactive_employee_cannot_be_issued_to(): void
    {
        $officer = $this->officer0();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staffMember();
        $kofi->employee->update(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->issues()->issue($officer, $this->data(['employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'is_historic' => true, 'issued_on' => '2023-05-01']));
    }

    public function test_an_inactive_type_cannot_be_issued(): void
    {
        $officer = $this->officer0();
        $hat = $this->ppeType(['name' => 'Hard hat', 'is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->issues()->issue($officer, $this->data(['employee_id' => $this->staffMember()->employee->id, 'ppe_type_id' => $hat->id, 'is_historic' => true, 'issued_on' => '2023-05-01']));
    }

    public function test_a_super_admin_account_is_a_valid_issuer_for_staff_anywhere(): void
    {
        $super = $this->superAdmin();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $ashantiStaff = $this->userWithRoles('300090', ['employee'], $this->ashanti, $this->kumasi);

        $issue = $this->issues()->issue($super, $this->data(['employee_id' => $ashantiStaff->employee->id, 'ppe_type_id' => $hat->id, 'is_historic' => true, 'issued_on' => '2024-01-01']));

        $this->assertInstanceOf(Employee::class, $issue->employee);
        $this->assertSame($super->id, $issue->issued_by);
    }
}
