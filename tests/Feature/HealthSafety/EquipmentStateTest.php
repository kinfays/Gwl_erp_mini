<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Services\HealthSafety\EquipmentExpiryService;

class EquipmentStateTest extends HealthSafetyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The windows are placeholders in config; pin them so the fixtures below mean what they say.
        config([
            'gwl.hs_expiry_warning_days' => 60,
            'gwl.hs_expiry_critical_days' => 30,
            'gwl.hs_check_interval_days' => 30,
        ]);
    }

    private function date(int $daysFromToday): string
    {
        return today()->addDays($daysFromToday)->toDateString();
    }

    // ---------------------------------------------------------------- extinguishers: each state

    public function test_each_extinguisher_state_is_reached_by_the_right_fixture(): void
    {
        $cases = [
            'expired' => ['expiry_date' => $this->date(-1)],
            'service_overdue' => ['next_service_due' => $this->date(-1)],
            'hydro_overdue' => ['next_hydro_test_due' => $this->date(-1)],
            'expiring' => ['expiry_date' => $this->date(59)],
            'service_due_soon' => ['next_service_due' => $this->date(10)],
            'check_failed' => ['last_check_result' => 'fail'],
            'check_overdue' => ['last_checked_on' => $this->date(-30)],
            'ok' => ['expiry_date' => $this->date(365), 'next_service_due' => $this->date(200), 'next_hydro_test_due' => $this->date(900)],
        ];

        foreach ($cases as $expected => $attributes) {
            $unit = $this->unit($attributes);

            $this->assertSame($expected, $unit->state(), "fixture for {$expected}");
            $this->assertTrue(HsFireExtinguisher::query()->withState($expected)->whereKey($unit->id)->exists(), "scope for {$expected}");
        }
    }

    public function test_a_hydrostatic_test_coming_up_counts_as_a_service_due_soon(): void
    {
        $this->assertSame('service_due_soon', $this->unit(['next_hydro_test_due' => $this->date(20)])->state());
    }

    public function test_the_window_edges_are_inclusive_and_dates_in_the_past_are_not_expiring(): void
    {
        $this->assertSame('expiring', $this->unit(['expiry_date' => $this->date(0)])->state(), 'expires today: still in date, but expiring');
        $this->assertSame('expiring', $this->unit(['expiry_date' => $this->date(60)])->state(), 'the last day of the window');
        $this->assertSame('ok', $this->unit(['expiry_date' => $this->date(61)])->state());
        $this->assertSame('expired', $this->unit(['expiry_date' => $this->date(-1)])->state());
    }

    // ---------------------------------------------------------------- extinguishers: the order

    public function test_first_match_wins_for_extinguishers(): void
    {
        $everything = $this->unit([
            'expiry_date' => $this->date(-5), 'next_service_due' => $this->date(-5), 'next_hydro_test_due' => $this->date(-5),
            'last_check_result' => 'fail', 'last_checked_on' => $this->date(-90),
        ]);
        $this->assertSame('expired', $everything->state(), 'expired beats service overdue, hydro overdue, a failed check and an overdue one');

        $this->assertSame('service_overdue', $this->unit(['next_service_due' => $this->date(-5), 'next_hydro_test_due' => $this->date(-5), 'expiry_date' => $this->date(10)])->state(), 'service overdue beats hydro overdue and expiring');
        $this->assertSame('hydro_overdue', $this->unit(['next_hydro_test_due' => $this->date(-5), 'expiry_date' => $this->date(10)])->state(), 'hydro overdue beats expiring');
        // Design 8.16 item 1: a failed check beats expiring and due soon, and loses to the three overdue states.
        $this->assertSame('check_failed', $this->unit(['expiry_date' => $this->date(10), 'next_service_due' => $this->date(10), 'last_check_result' => 'fail'])->state(), 'a failed check beats expiring and due soon');
        $this->assertSame('check_failed', $this->unit(['next_service_due' => $this->date(10), 'last_check_result' => 'fail'])->state(), 'a failed check beats due soon');
        $this->assertSame('expiring', $this->unit(['expiry_date' => $this->date(10), 'next_service_due' => $this->date(10), 'last_check_result' => 'pass'])->state(), 'without the failed check it is expiring');
        $this->assertSame('expired', $this->unit(['expiry_date' => $this->date(-1), 'last_check_result' => 'fail'])->state(), 'expired beats a failed check');
        $this->assertSame('service_overdue', $this->unit(['next_service_due' => $this->date(-1), 'last_check_result' => 'fail'])->state(), 'service overdue beats a failed check');
        $this->assertSame('hydro_overdue', $this->unit(['next_hydro_test_due' => $this->date(-1), 'last_check_result' => 'fail'])->state(), 'hydro overdue beats a failed check');

        foreach (['check_failed' => ['expiry_date' => $this->date(10), 'last_check_result' => 'fail'], 'expiring' => ['expiry_date' => $this->date(10), 'last_check_result' => 'pass']] as $expected => $attributes) {
            $unit = $this->unit($attributes);
            $this->assertTrue(HsFireExtinguisher::query()->withState($expected)->whereKey($unit->id)->exists(), "the SQL twin agrees: {$expected}");
        }
        $failedAndExpiring = $this->unit(['expiry_date' => $this->date(10), 'last_check_result' => 'fail']);
        $this->assertFalse(HsFireExtinguisher::query()->withState('expiring')->whereKey($failedAndExpiring->id)->exists(), 'the SQL twin no longer lists a failed-and-expiring unit as expiring');
        $this->assertSame('check_failed', $this->unit(['last_check_result' => 'fail', 'last_checked_on' => $this->date(-90)])->state(), 'a failed check beats an overdue one');
    }

    public function test_a_failed_check_is_only_a_state_for_the_latest_check_and_clears_when_the_next_passes(): void
    {
        $officer = $this->officer();
        $unit = $this->unit(['expiry_date' => $this->date(300), 'last_check_result' => 'fail']);
        $this->assertSame('check_failed', $unit->state());

        $unit->update(['last_check_result' => 'pass']);
        $this->assertSame('ok', $unit->fresh()->state());
        $this->assertNotNull($officer);
    }

    // ---------------------------------------------------------------- kits

    public function test_each_kit_state_is_reached_by_the_right_fixture(): void
    {
        $cases = [
            'missing' => [['status' => 'missing'], []],
            'item_expired' => [[], [['Gauze', 2, 2, $this->date(-1)]]],
            'item_expiring' => [[], [['Gauze', 2, 2, $this->date(30)]]],
            'incomplete' => [[], [['Gauze', 5, 2, null]]],
            'check_failed' => [['last_check_result' => 'fail'], [['Gauze', 2, 2, null]]],
            'check_overdue' => [['last_checked_on' => $this->date(-30)], [['Gauze', 2, 2, null]]],
            'ok' => [[], [['Gauze', 2, 2, $this->date(300)], ['Scissors', 1, 3, null]]],
        ];

        foreach ($cases as $expected => [$attributes, $items]) {
            $kit = $this->kit($attributes, $items);

            $this->assertSame($expected, $kit->fresh()->state(), "fixture for {$expected}");
            $this->assertTrue(HsFirstAidKit::query()->withState($expected)->whereKey($kit->id)->exists(), "scope for {$expected}");
        }
    }

    public function test_first_match_wins_for_kits_and_missing_beats_everything(): void
    {
        $missing = $this->kit(['status' => 'missing', 'last_check_result' => 'fail', 'last_checked_on' => $this->date(-90)], [['Gauze', 5, 0, $this->date(-10)]]);
        $this->assertSame('missing', $missing->fresh()->state());

        $this->assertSame('item_expired', $this->kit(['last_check_result' => 'fail'], [['Gauze', 5, 0, $this->date(-1)], ['Tape', 1, 1, $this->date(10)]])->fresh()->state(), 'an expired item beats an expiring one, a shortage and a failed check');
        $this->assertSame('item_expiring', $this->kit([], [['Gauze', 5, 0, $this->date(10)]])->fresh()->state(), 'an expiring item beats a shortage');
        $this->assertSame('incomplete', $this->kit(['last_check_result' => 'fail'], [['Gauze', 5, 0, null]])->fresh()->state(), 'a shortage beats a failed check');
        $this->assertSame('check_failed', $this->kit(['last_check_result' => 'fail', 'last_checked_on' => $this->date(-90)], [['Gauze', 1, 1, null]])->fresh()->state(), 'a failed check beats an overdue one');
    }

    public function test_a_kit_with_no_contents_is_not_incomplete(): void
    {
        $this->assertSame('ok', $this->kit()->fresh()->state());
    }

    // ---------------------------------------------------------------- not evaluated

    public function test_decommissioned_and_discharged_units_are_left_out_of_every_attention_list(): void
    {
        $expired = ['expiry_date' => $this->date(-100), 'last_check_result' => 'fail', 'last_checked_on' => $this->date(-200)];

        $decommissioned = $this->unit([...$expired, 'status' => 'decommissioned']);
        $discharged = $this->unit([...$expired, 'status' => 'discharged']);
        $outForService = $this->unit([...$expired, 'status' => 'out_for_service']);
        $live = $this->unit($expired);

        $this->assertNull($decommissioned->state());
        $this->assertNull($discharged->state());
        $this->assertSame('expired', $outForService->state(), 'a unit out for service still has dates that run out');

        $ids = HsFireExtinguisher::query()->needsAttention()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$outForService->id, $live->id], $ids);

        $this->assertSame(0, HsFireExtinguisher::query()->whereKey([$decommissioned->id, $discharged->id])->expired()->count());
        $this->assertSame(0, HsFireExtinguisher::query()->whereKey([$decommissioned->id, $discharged->id])->checkOverdue()->count());

        $kit = $this->kit(['status' => 'decommissioned', 'last_checked_on' => $this->date(-200)], [['Gauze', 5, 0, $this->date(-5)]]);
        $this->assertNull($kit->fresh()->state());
        $this->assertSame(0, HsFirstAidKit::query()->needsAttention()->whereKey($kit->id)->count());
    }

    // ---------------------------------------------------------------- the check baseline

    public function test_a_never_checked_item_is_measured_from_the_day_it_was_added(): void
    {
        $fresh = $this->unit(['last_checked_on' => null, 'last_check_result' => null]);
        $fresh->forceFill(['created_at' => today()->subDay()])->save();
        $this->assertSame('ok', $fresh->fresh()->state(), 'added yesterday: not overdue');

        $old = $this->unit(['last_checked_on' => null, 'last_check_result' => null]);
        $old->forceFill(['created_at' => today()->subDays(30)])->save();
        $this->assertSame('check_overdue', $old->fresh()->state(), 'added a full interval ago: overdue');

        $almost = $this->unit(['last_checked_on' => null, 'last_check_result' => null]);
        $almost->forceFill(['created_at' => today()->subDays(29)])->save();
        $this->assertSame('ok', $almost->fresh()->state(), 'one day short of the interval');

        $this->assertEqualsCanonicalizing([$old->id], HsFireExtinguisher::query()->checkOverdue()->pluck('id')->all());

        $checkedLongAgo = $this->unit(['last_checked_on' => $this->date(-31)]);
        $checkedLongAgo->forceFill(['created_at' => today()->subDay()])->save();
        $this->assertSame('check_overdue', $checkedLongAgo->fresh()->state(), 'a recorded check wins over the day it was added');
    }

    public function test_the_check_interval_is_read_from_config(): void
    {
        $unit = $this->unit(['last_checked_on' => $this->date(-10)]);
        $this->assertSame('ok', $unit->state());

        config(['gwl.hs_check_interval_days' => 7]);
        $this->assertSame('check_overdue', $unit->fresh()->state());
        $this->assertTrue(HsFireExtinguisher::query()->withState('check_overdue')->whereKey($unit->id)->exists());
    }

    // ---------------------------------------------------------------- SQL and PHP agree

    public function test_the_sql_scopes_and_the_php_state_agree_across_a_grid_of_fixtures(): void
    {
        $expiries = [null, -40, -1, 0, 20, 45, 60, 61, 400];
        $services = [null, -3, 0, 15, 70];
        $hydros = [null, -3, 10, 500];
        $checks = [[null, null], [0, 'pass'], [-29, 'pass'], [-30, 'pass'], [-3, 'fail']];
        $statuses = ['in_service', 'out_for_service', 'discharged', 'decommissioned'];

        $site = $this->site('Grid Site');
        $count = 0;

        foreach ($expiries as $e) {
            foreach ($services as $s) {
                foreach ($hydros as $h) {
                    foreach ($checks as [$c, $result]) {
                        $unit = $this->unit([
                            'expiry_date' => $e === null ? null : $this->date($e),
                            'next_service_due' => $s === null ? null : $this->date($s),
                            'next_hydro_test_due' => $h === null ? null : $this->date($h),
                            'last_checked_on' => $c === null ? null : $this->date($c),
                            'last_check_result' => $result,
                            'status' => $statuses[$count % 4],
                        ], $site);

                        // Never-checked items are measured from creation: spread them either side of the interval.
                        if ($c === null) {
                            $unit->forceFill(['created_at' => today()->subDays($count % 2 === 0 ? 5 : 40)])->save();
                        }

                        $count++;
                    }
                }
            }
        }

        $this->assertGreaterThan(800, $count);
        $this->assertStatesAgree(HsFireExtinguisher::class);
    }

    public function test_the_sql_scopes_and_the_php_state_agree_for_kits(): void
    {
        $itemSets = [
            [],
            [['A', 2, 2, null]],
            [['A', 2, 1, null]],
            [['A', 2, 2, $this->date(-1)]],
            [['A', 2, 2, $this->date(0)]],
            [['A', 2, 2, $this->date(60)]],
            [['A', 2, 2, $this->date(61)]],
            [['A', 2, 2, $this->date(100)], ['B', 1, 0, null]],
            [['A', 2, 2, $this->date(100)], ['B', 1, 1, $this->date(-5)]],
        ];
        $checks = [[null, null], [0, 'pass'], [-30, 'pass'], [-3, 'fail']];
        $statuses = ['in_service', 'missing', 'decommissioned'];

        $site = $this->site('Grid Site');
        $count = 0;

        foreach ($itemSets as $items) {
            foreach ($checks as [$c, $result]) {
                foreach ($statuses as $status) {
                    $kit = $this->kit([
                        'last_checked_on' => $c === null ? null : $this->date($c),
                        'last_check_result' => $result,
                        'status' => $status,
                    ], $items, $site);

                    if ($c === null) {
                        $kit->forceFill(['created_at' => today()->subDays($count % 2 === 0 ? 5 : 40)])->save();
                    }

                    $count++;
                }
            }
        }

        $this->assertGreaterThan(100, $count);
        $this->assertStatesAgree(HsFirstAidKit::class);
    }

    /** @param  class-string<\Illuminate\Database\Eloquent\Model>  $model */
    private function assertStatesAgree(string $model): void
    {
        $rows = $model::query()->get();
        $total = 0;

        foreach ($model::stateNames() as $state) {
            $fromSql = $model::query()->withState($state)->pluck('id')->sort()->values()->all();
            $fromPhp = $rows->filter(fn ($row) => $row->state() === $state)->pluck('id')->sort()->values()->all();

            $this->assertSame($fromPhp, $fromSql, "SQL and PHP disagree on [{$state}]");
            $total += count($fromSql);
        }

        $this->assertSame($rows->filter(fn ($row) => $row->state() !== null)->count(), $total, 'every evaluated row falls in exactly one state');

        $attention = $model::query()->needsAttention()->pluck('id')->sort()->values()->all();
        $this->assertSame($rows->filter(fn ($row) => $row->state() !== null && $row->state() !== 'ok')->pluck('id')->sort()->values()->all(), $attention, 'needsAttention is every state but ok');

        // The standalone scopes the overview uses match the same rows.
        if ($model === HsFireExtinguisher::class) {
            $this->assertSame($rows->filter(fn ($row) => $row->isEvaluated() && $row->expiry_date && $row->expiry_date->lt(today()))->count(), $model::query()->expired()->count());
            $this->assertSame($rows->filter(fn ($row) => $row->isEvaluated() && $row->expiry_date && $row->expiry_date->gte(today()) && $row->expiry_date->lte(today()->addDays(30)))->count(), $model::query()->expiringWithin(30)->count());
        }
    }

    // ---------------------------------------------------------------- overview counts match the lists

    public function test_the_overview_counts_match_the_filtered_lists(): void
    {
        $officer = $this->officer();

        $this->unit(['expiry_date' => $this->date(-2)]);
        $this->unit(['expiry_date' => $this->date(-9)]);
        $this->unit(['expiry_date' => $this->date(10)]);
        $this->unit(['expiry_date' => $this->date(29)]);
        $this->unit(['expiry_date' => $this->date(45)]);
        $this->unit(['last_checked_on' => $this->date(-40)]);
        $this->kit([], [['Gauze', 2, 2, $this->date(-1)]]);
        $this->kit([], [['Gauze', 2, 2, $this->date(12)]]);
        $this->kit([], [['Gauze', 2, 2, $this->date(500)]]);
        $this->kit(['last_checked_on' => $this->date(-31)], [['Gauze', 2, 2, null]]);

        $service = app(EquipmentExpiryService::class);
        $overview = $service->overview($officer);

        $this->assertSame(2, $overview['extinguishers_expired']['count']);
        $this->assertSame(2, $overview['extinguishers_expiring']['count']);
        $this->assertSame(1, $overview['extinguishers_check_overdue']['count']);
        $this->assertSame(1, $overview['kits_expired']['count']);
        $this->assertSame(1, $overview['kits_expiring']['count']);
        $this->assertSame(1, $overview['kits_check_overdue']['count']);

        foreach (['extinguishers_expired', 'extinguishers_expiring', 'extinguishers_check_overdue'] as $key) {
            $this->assertSame($overview[$key]['count'], $service->extinguishers($officer, $overview[$key]['params'])->get()->count(), "{$key}: the list is the count");
        }

        foreach (['kits_expired', 'kits_expiring', 'kits_check_overdue'] as $key) {
            $this->assertSame($overview[$key]['count'], $service->kits($officer, $overview[$key]['params'])->get()->count(), "{$key}: the list is the count");
        }
    }
}
