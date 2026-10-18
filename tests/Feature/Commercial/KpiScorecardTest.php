<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\KpiTargets;
use App\Livewire\Commercial\Kpis;
use App\Models\AuditLog;
use App\Models\CommercialDistrictTarget;
use App\Models\CommercialImportBatch;
use App\Models\District;
use App\Services\Commercial\CommercialKpiService;
use App\Services\Commercial\CommercialReportData;
use App\Support\ErpNavigation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * The district KPI table (sales, billing, collection against targets, efficiency) and the arrears table. Mamprobi's figures are the
 * ones the Commercial team showed as an example (target 125,976 m3 / GH¢ 1,425,674 / GH¢ 1,425,674; August to October actuals): the
 * efficiencies the team prints are the ones this must reproduce. Synthetic data otherwise.
 */
class KpiScorecardTest extends \Tests\Feature\Commercial\Customers\CustomerTestCase
{
    protected District $mamprobi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mamprobi = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Mamprobi']);
    }

    /** A single route of a district for one month. @return array<string, mixed> */
    protected function route(string $district, float $volume, float $billing, float $payments, float $closing, string $code = '1'): array
    {
        return ['district' => $district, 'code' => $district.' '.$code, 'volume_total' => $volume, 'billing_for_period' => $billing, 'total_payments' => $payments, 'closing_balance' => $closing];
    }

    protected function month(string $month, array $routes, array $options = []): CommercialImportBatch
    {
        return $this->seedBilling($routes, $month, $options + ['districts' => ['MAMPROBI' => $this->mamprobi->id, 'SOWUTUOM' => $this->sowutuom->id, 'ODORKOR' => $this->odorkor->id]]);
    }

    /** Mamprobi, August to October, as in the team's example. */
    protected function sample(): void
    {
        $this->month('2026-08-01', [$this->route('MAMPROBI', 130072, 1515645, 1427607, 10171763)]);
        $this->month('2026-09-01', [$this->route('MAMPROBI', 126219, 1474693, 1542128, 10099467)]);
        $this->month('2026-10-01', [$this->route('MAMPROBI', 143043, 1663315, 1507830, 10252278)]);
    }

    /**
     * Targets are YEARLY: stored on 1 January of the year of $from. The figures given are the MONTHLY equivalents the team quotes, so the
     * annual target stored is twelve times each (and a month's target, annual / 12, is exactly the figure given).
     */
    protected function targets(District $district, float $sales, float $billing, float $collection, string $from = '2026-08-01'): void
    {
        foreach (['sales' => $sales, 'billing' => $billing, 'collection' => $collection] as $metric => $value) {
            CommercialDistrictTarget::query()->create(['district_id' => $district->id, 'metric' => $metric, 'effective_from' => substr($from, 0, 4).'-01-01', 'value' => $value * 12]);
        }
    }

    protected function card(?int $region = null, ?string $segment = null, int $months = 3, ?int $district = null, $user = null): array
    {
        $this->actingAs($user ?? $this->officer());

        return app(CommercialReportData::class)->kpis($region, $segment, $months, $district);
    }

    /** @return array<string, mixed> the block of one district */
    protected function block(array $card, string $name): array
    {
        return collect($card['districts'])->firstWhere('name', $name);
    }

    protected function row(array $block, string $metric): array
    {
        return collect($block['rows'])->firstWhere('metric', $metric);
    }

    // ---------------------------------------------------------------- the example from the Commercial team

    public function test_the_efficiencies_are_the_ones_the_commercial_team_prints(): void
    {
        $this->sample();
        $this->targets($this->mamprobi, 125976, 1425674, 1425674);

        $block = $this->block($this->card(), 'Mamprobi');

        $sales = $this->row($block, 'sales');
        $this->assertSame(125976.0, $sales['target']);
        $this->assertSame([130072.0, 126219.0, 143043.0], array_values($sales['actuals']));
        $this->assertSame([103.3, 100.2, 113.5], array_values($sales['efficiency']));

        $this->assertSame([1515645.0, 1474693.0, 1663315.0], array_values($this->row($block, 'billing')['actuals']));
        $this->assertSame([106.3, 103.4, 116.7], array_values($this->row($block, 'billing')['efficiency']));

        $this->assertSame([1427607.0, 1542128.0, 1507830.0], array_values($this->row($block, 'collection')['actuals']));
        $this->assertSame([100.1, 108.2, 105.8], array_values($this->row($block, 'collection')['efficiency']));

        $arrears = $this->row($block, 'arrears');
        $this->assertSame([10171763.0, 10099467.0, 10252278.0], array_values($arrears['actuals']));
        $this->assertNull($arrears['target'], 'arrears have no target');
        $this->assertSame([], $arrears['efficiency']);
    }

    public function test_the_month_labels_are_the_months_of_the_uploads_oldest_first(): void
    {
        $this->sample();

        $card = $this->card();

        $this->assertSame(['AUG', 'SEP', 'OCT'], array_column($card['months'], 'short'));
        $this->assertSame(['August', 'September', 'October'], array_column($card['months'], 'label'));
    }

    // ---------------------------------------------------------------- targets

    public function test_without_a_target_there_is_an_actual_and_no_efficiency_and_a_zero_target_is_not_divided_by(): void
    {
        $this->sample();

        $sales = $this->row($this->block($this->card(), 'Mamprobi'), 'sales');
        $this->assertNull($sales['target']);
        $this->assertSame([null, null, null], array_values($sales['efficiency']));
        $this->assertSame(130072.0, $sales['actuals']['2026-08']);

        $this->targets($this->mamprobi, 0, 0, 0);
        $this->assertSame([null, null, null], array_values($this->row($this->block($this->card(), 'Mamprobi'), 'billing')['efficiency']));
    }

    public function test_a_target_is_for_its_own_year_and_each_month_is_judged_against_its_own_years_target(): void
    {
        $this->month('2026-09-01', [$this->route('MAMPROBI', 130072, 1515645, 1427607, 10171763)]);
        $this->month('2026-10-01', [$this->route('MAMPROBI', 126219, 1474693, 1542128, 10099467)]);
        $this->month('2027-01-01', [$this->route('MAMPROBI', 143043, 1663315, 1507830, 10252278)]);
        $this->targets($this->mamprobi, 100000, 1000000, 1000000, '2026-01-01');
        $this->targets($this->mamprobi, 200000, 2000000, 2000000, '2027-01-01');

        $sales = $this->row($this->block($this->card(null, null, 3), 'Mamprobi'), 'sales');

        $this->assertSame([100000.0, 100000.0, 200000.0], array_values($sales['targets']), 'annual / 12 of the month\'s own year');
        $this->assertSame(200000.0, $sales['target'], 'the column shows the latest month\'s target');
        $this->assertSame(2400000.0, $sales['annual']);
        $this->assertSame(2027, $sales['annual_year']);
        $this->assertTrue($sales['target_changes']);
        $this->assertSame([130.1, 126.2, 71.5], array_values($sales['efficiency']), 'each month against its own year\'s target');
    }

    public function test_a_year_without_a_target_has_none_and_nothing_carries_over_from_another_year(): void
    {
        $this->sample();
        $this->targets($this->mamprobi, 100000, 1000000, 1000000, '2025-01-01');   // last year's target only

        $sales = $this->row($this->block($this->card(), 'Mamprobi'), 'sales');

        $this->assertSame([null, null, null], array_values($sales['targets']));
        $this->assertNull($sales['annual']);
        $this->assertNull($sales['efficiency']['2026-08']);
        $this->assertNull($sales['ytd']['efficiency']);

        $this->targets($this->mamprobi, 100000, 1000000, 1000000, '2027-01-01');   // next year's does not apply either
        $this->assertNull($this->row($this->block($this->card(), 'Mamprobi'), 'sales')['annual']);
    }

    public function test_the_year_to_date_compares_the_months_uploaded_with_their_share_of_the_annual_target(): void
    {
        $this->sample();   // August, September, October 2026
        $this->targets($this->mamprobi, 125976, 1425674, 1425674);

        $sales = $this->row($this->block($this->card(), 'Mamprobi'), 'sales');

        $this->assertSame(1511712.0, $sales['annual']);
        $this->assertSame(2026, $sales['annual_year']);
        $this->assertSame(3, $sales['ytd']['months']);
        $this->assertSame(399334.0, $sales['ytd']['actual'], '130,072 + 126,219 + 143,043');
        $this->assertSame(377928.0, $sales['ytd']['target'], 'annual x 3 / 12');
        $this->assertSame(105.7, $sales['ytd']['efficiency'], '399,334 / 377,928');
        $this->assertSame(26.4, $sales['ytd']['progress'], 'of the whole year\'s target: 399,334 / 1,511,712');

        $this->assertNull($this->row($this->block($this->card(), 'Mamprobi'), 'arrears')['ytd']['efficiency'], 'arrears have no target');
    }

    public function test_a_month_that_was_never_uploaded_does_not_count_against_the_year_to_date(): void
    {
        $this->month('2026-03-01', [$this->route('MAMPROBI', 1000, 10000, 9000, 500)]);
        $this->month('2026-09-01', [$this->route('MAMPROBI', 1000, 10000, 9000, 500)]);   // April to August were never uploaded
        $this->targets($this->mamprobi, 1000, 10000, 10000);

        $sales = $this->row($this->block($this->card(null, null, 12), 'Mamprobi'), 'sales');

        $this->assertSame(2, $sales['ytd']['months']);
        $this->assertSame(2000.0, $sales['ytd']['target'], 'two months of an annual 12,000');
        $this->assertSame(100.0, $sales['ytd']['efficiency']);
    }

    public function test_the_charts_use_the_same_figures_as_the_table(): void
    {
        $this->sample();
        $this->targets($this->mamprobi, 125976, 1425674, 1425674);

        $card = $this->card();
        $charts = app(CommercialKpiService::class)->charts($card);

        $this->assertSame(['AUG 26', 'SEP 26', 'OCT 26'], $charts['labels']);
        $this->assertSame([130072.0, 126219.0, 143043.0], $charts['trend']['sales']['series'][0]['data']);
        $this->assertSame([125976.0, 125976.0, 125976.0], $charts['trend']['sales']['series'][1]['data'], 'the monthly target: annual / 12');
        $this->assertSame(['Mamprobi'], $charts['districts']);
        $this->assertSame([105.7], $charts['ytd_efficiency']['sales']);
        $this->assertSame([10171763.0, 10099467.0, 10252278.0], $charts['arrears_owed']);
        $this->assertSame('All districts', $charts['scope']);
    }

    // ---------------------------------------------------------------- the region total

    public function test_the_region_total_adds_the_districts_and_shows_efficiency_only_when_every_district_has_a_target(): void
    {
        $this->month('2026-09-01', [$this->route('MAMPROBI', 1000, 10000, 9000, 500), $this->route('SOWUTUOM', 3000, 30000, 33000, 700)]);
        $this->targets($this->mamprobi, 800, 9000, 9000, '2026-09-01');

        $card = $this->card();
        $total = $card['total'];

        $this->assertSame('All districts', $total['name']);
        $this->assertSame(4000.0, $this->row($total, 'sales')['actuals']['2026-09']);
        $this->assertSame(40000.0, $this->row($total, 'billing')['actuals']['2026-09']);
        $this->assertSame(1200.0, $this->row($total, 'arrears')['actuals']['2026-09']);
        $this->assertNull($this->row($total, 'sales')['target'], 'Sowutuom has no target, so the region has none: a partial sum would flatter it');
        $this->assertSame([null], array_values($this->row($total, 'sales')['efficiency']));
        $this->assertFalse($total['complete']);

        $this->targets($this->sowutuom, 3000, 30000, 30000, '2026-09-01');
        $total = $this->card()['total'];

        $this->assertSame(3800.0, $this->row($total, 'sales')['target']);
        $this->assertSame(105.3, $this->row($total, 'sales')['efficiency']['2026-09']);
        $this->assertSame(102.6, $this->row($total, 'billing')['efficiency']['2026-09'], '40,000 / 39,000');
        $this->assertTrue($total['complete']);
    }

    public function test_the_districts_are_listed_by_name_and_a_district_can_be_picked(): void
    {
        $this->month('2026-09-01', [$this->route('SOWUTUOM', 1, 1, 1, 1), $this->route('MAMPROBI', 2, 2, 2, 2), $this->route('ODORKOR', 3, 3, 3, 3)]);

        $card = $this->card();
        $this->assertSame(['Mamprobi', 'Odorkor', 'Sowutuom'], array_column($card['districts'], 'name'));

        $picked = $this->card(null, null, 3, $this->odorkor->id);
        $this->assertSame(['Odorkor'], array_column($picked['districts'], 'name'));
        $this->assertSame([$this->mamprobi->id => 'Mamprobi', $this->odorkor->id => 'Odorkor', $this->sowutuom->id => 'Sowutuom'], $picked['district_options']);
    }

    // ---------------------------------------------------------------- arrears

    public function test_arrears_are_what_customers_owe_with_the_credits_beside_them_never_netted_off(): void
    {
        $this->month('2026-08-01', [$this->route('MAMPROBI', 1, 1000, 0, 5000, 'A'), $this->route('MAMPROBI', 1, 1000, 0, -400, 'B')]);
        $this->month('2026-09-01', [$this->route('MAMPROBI', 1, 2000, 0, 6000, 'A'), $this->route('MAMPROBI', 1, 2000, 0, -1000, 'B')]);

        $block = $this->block($this->card(), 'Mamprobi');
        $arrears = $block['arrears'];

        $this->assertSame(['August', 'September'], array_column($arrears, 'label'));
        $this->assertSame([5000.0, 6000.0], array_column($arrears, 'arrears'), 'only balances above zero are arrears');
        $this->assertSame([400.0, 1000.0], array_column($arrears, 'credit'), 'a negative balance is a customer credit');
        $this->assertSame([4600.0, 5000.0], array_column($arrears, 'net'), 'the net balance is shown, but it is not the arrears');
        $this->assertSame([1, 1], array_column($arrears, 'credit_routes'));
        $this->assertSame(['routes', 'routes'], array_column($arrears, 'source'));
        $this->assertSame([2.5, 1.5], array_column($arrears, 'age'), 'arrears divided by the billing of that month (2,000 and 4,000)');
        $this->assertSame([null, 1000.0], array_column($arrears, 'change'));
        $this->assertSame([null, 20.0], array_column($arrears, 'change_pct'));
        $this->assertSame([5000.0, 6000.0], array_values($this->row($block, 'arrears')['actuals']), 'the KPI row is the same figure');
    }

    public function test_a_customer_list_upload_of_the_month_gives_the_exact_owed_and_credit_figures(): void
    {
        $this->month('2026-08-01', [$this->route('SOWUTUOM', 10, 1000, 0, 400, 'A')]);
        $this->month('2026-09-01', [$this->route('SOWUTUOM', 10, 2000, 0, 100, 'A')]);

        // September's customer list (as of 28 Sep): balances 101, 102, 103 are owed, and one customer is GH¢ 20 in credit
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [
            \Tests\Support\Commercial\ReportWorkbooks::customer(1), \Tests\Support\Commercial\ReportWorkbooks::customer(2), \Tests\Support\Commercial\ReportWorkbooks::customer(3),
            \Tests\Support\Commercial\ReportWorkbooks::customer(4, ['balance' => -20.0]),
        ]]]], '2026-09-28');

        $arrears = $this->block($this->card(), 'Sowutuom')['arrears'];

        $this->assertSame(['routes', 'customers'], array_column($arrears, 'source'), 'August has no customer list upload');
        $this->assertSame([400.0, 306.0], array_column($arrears, 'arrears'), 'September: 101 + 102 + 103 owed, exact, per customer');
        $this->assertSame([0.0, 20.0], array_column($arrears, 'credit'));
        $this->assertSame([400.0, 286.0], array_column($arrears, 'net'));
        $this->assertSame([0.4, 0.2], array_column($arrears, 'age'), 'August 400 / 1,000 (routes); September 306 / 2,000 = 0.15, shown to one decimal');
    }

    public function test_the_age_is_blank_with_no_billing_or_no_arrears(): void
    {
        $this->month('2026-08-01', [$this->route('MAMPROBI', 1, 0, 0, 5000)]);
        $this->month('2026-09-01', [$this->route('MAMPROBI', 1, 1000, 0, -300)]);

        $this->assertSame([null, null], array_column($this->block($this->card(), 'Mamprobi')['arrears'], 'age'));
    }

    // ---------------------------------------------------------------- which snapshots count

    public function test_only_single_month_effective_snapshots_of_the_chosen_region_and_segment_count(): void
    {
        $this->month('2026-08-01', [$this->route('MAMPROBI', 100, 1000, 900, 50)]);
        $this->month('2026-09-01', [$this->route('MAMPROBI', 100, 1000, 900, 50)], ['status' => CommercialImportBatch::STATUS_VOIDED]);
        $this->month('2026-07-01', [$this->route('MAMPROBI', 999, 9999, 999, 99)], ['period_to' => '2026-09-30']);               // a three-month period
        $this->month('2026-08-01', [$this->route('MAMPROBI', 555, 5555, 555, 55)], ['segment' => 'all']);                         // another segment
        $newer = $this->month('2026-08-01', [$this->route('MAMPROBI', 777, 7777, 777, 77)]);                                       // replaces the first August upload

        $card = $this->card(null, 'new_service');

        $this->assertSame(['2026-08'], array_column($card['months'], 'key'), 'one single-month snapshot of this segment');
        $this->assertSame([$newer->id], array_column($card['months'], 'snapshot_id'));
        $this->assertSame(777.0, $this->row($this->block($card, 'Mamprobi'), 'sales')['actuals']['2026-08']);
        $this->assertSame(['new_service', 'all'], array_keys($card['segments']));

        $other = $this->card(null, 'all');
        $this->assertSame(555.0, $this->row($this->block($other, 'Mamprobi'), 'sales')['actuals']['2026-08']);
    }

    public function test_the_last_n_months_are_shown(): void
    {
        foreach (['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01'] as $month) {
            $this->month($month, [$this->route('MAMPROBI', 1, 1, 1, 1)]);
        }

        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($this->card(null, null, 3)['months'], 'key'));
        $this->assertCount(5, $this->card(null, null, 12)['months']);
        $this->assertCount(1, $this->card(null, null, 1)['months']);
    }

    public function test_a_viewer_only_ever_gets_their_own_region_and_a_super_admin_can_choose(): void
    {
        $this->month('2026-09-01', [$this->route('MAMPROBI', 100, 1000, 900, 50)]);
        $this->month('2026-09-01', [$this->route('KUMASI', 7, 70, 7, 7)], ['region' => $this->ashanti, 'districts' => ['KUMASI' => $this->kumasi->id]]);

        $west = $this->card(null, null, 3, null, $this->officer('910501', $this->accraWest, $this->sowutuom));
        $this->assertSame([$this->accraWest->id], array_keys($west['regions']));
        $this->assertSame(['Mamprobi'], array_column($west['districts'], 'name'));

        $asked = $this->card($this->ashanti->id, null, 3, null, $this->officer('910502', $this->accraWest, $this->sowutuom));
        $this->assertSame([$this->accraWest->id], array_keys($asked['regions']), 'another region is not even offered');
        $this->assertSame($this->accraWest->id, $asked['region_id'], 'a requested region that is not theirs falls back silently');

        $all = $this->card($this->ashanti->id, null, 3, null, $this->superAdmin());
        $this->assertEqualsCanonicalizing([$this->accraWest->id, $this->ashanti->id], array_keys($all['regions']));
        $this->assertSame($this->ashanti->id, $all['region_id']);
        $this->assertSame(['Kumasi'], array_column($all['districts'], 'name'));
    }

    public function test_with_no_billing_there_is_an_empty_scorecard_not_an_error(): void
    {
        $card = $this->card();

        $this->assertTrue($card['empty']);
        $this->assertSame([], $card['regions']);
    }

    // ---------------------------------------------------------------- the screen

    public function test_the_screen_shows_the_table_the_team_uses(): void
    {
        $this->sample();
        $this->targets($this->mamprobi, 125976, 1425674, 1425674);

        Livewire::actingAs($this->officer())->test(Kpis::class)
            ->assertSee('District: Mamprobi')->assertSee('KPI')->assertSee('Annual target')->assertSee('Actuals')->assertSee('Efficiency')->assertSee('Year to date')
            ->assertSee('1,511,712')->assertSee('130,072')->assertSee('17,108,088.00')->assertSee('1,515,645.00')->assertSee('10,171,763.00')
            ->assertSee('103.3')->assertSee('116.7')->assertSee('105.8')
            ->assertSee('Arrears')->assertSee('Age of arrears')->assertSee('AUG')->assertSee('OCT')
            ->assertSee('A negative balance is a customer credit')->assertDontSee('to be confirmed');
    }

    public function test_the_screen_draws_the_charts_and_says_when_a_year_has_no_targets(): void
    {
        $this->sample();
        $this->targets($this->mamprobi, 125976, 1425674, 1425674);

        Livewire::actingAs($this->officer())->test(Kpis::class)
            ->assertSee('Sales: actual against target')->assertSee('Billing: actual against target')->assertSee('Collection: actual against target')
            ->assertSee('Year-to-date efficiency by district')->assertSee('Share of the annual target reached')
            ->assertSee('Arrears and customer credits')->assertSee('Age of arrears');

        CommercialDistrictTarget::query()->delete();

        Livewire::actingAs($this->officer())->test(Kpis::class)
            ->assertSee('No annual target is set for 2026 yet')->assertDontSee('Year-to-date efficiency by district')->assertSee('Sales: actual against target');
    }

    public function test_the_screen_needs_the_billing_permission_and_says_so_when_there_is_no_data(): void
    {
        $this->actingAs($this->officer())->get(route('commercial.kpis'))->assertOk()->assertSee('no single-month billing upload', false);
        $this->actingAs($this->userWith('910510', ['commercial.view_reading'], $this->accraWest, $this->sowutuom))->get(route('commercial.kpis'))->assertForbidden();

        $labels = collect(app(ErpNavigation::class)->build($this->officer(), 'commercial')['sidebar'])->pluck('label')->all();
        $this->assertContains('KPIs', $labels);
        $this->assertNotContains('KPIs', collect(app(ErpNavigation::class)->build($this->userWith('910511', ['commercial.view_reading'], $this->accraWest, $this->sowutuom), 'commercial')['sidebar'])->pluck('label')->all());
    }

    public function test_the_screen_filters_by_district_and_months(): void
    {
        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $month) {
            $this->month($month, [$this->route('MAMPROBI', 100, 1000, 900, 50), $this->route('ODORKOR', 7, 70, 7, 7)]);
        }

        Livewire::actingAs($this->officer())->test(Kpis::class)->assertSee('District: Mamprobi')->assertSee('District: Odorkor')->assertSee('All districts')
            ->set('district', (string) $this->odorkor->id)->assertSee('District: Odorkor')->assertDontSee('District: Mamprobi')
            ->set('district', '')->set('months', '2')->assertSee('AUG')->assertSee('SEP')->assertDontSee('JUL');
    }

    // ---------------------------------------------------------------- the targets editor

    public function test_only_a_holder_of_manage_settings_sets_targets(): void
    {
        $this->actingAs($this->officer())->get(route('commercial.kpis.targets'))->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('commercial.kpis.targets'))->assertOk()->assertSee('Mamprobi');
        Livewire::actingAs($this->officer())->test(KpiTargets::class)->assertForbidden();
    }

    public function test_targets_are_typed_per_district_for_a_year_and_audited(): void
    {
        $admin = $this->superAdmin();

        $page = Livewire::actingAs($admin)->test(KpiTargets::class)->set('year', '2026')
            ->set('grid.'.$this->mamprobi->id.'.sales', '125,976')->set('grid.'.$this->mamprobi->id.'.billing', '1425674.00')->set('grid.'.$this->mamprobi->id.'.collection', '1425674')
            ->call('save')->assertHasNoErrors();

        $rows = CommercialDistrictTarget::query()->where('district_id', $this->mamprobi->id)->orderBy('metric')->get();
        $this->assertSame(['billing' => 1425674.0, 'collection' => 1425674.0, 'sales' => 125976.0], $rows->pluck('value', 'metric')->all());
        $this->assertSame(['2026-01-01'], $rows->pluck('effective_from')->map->toDateString()->unique()->values()->all());
        $this->assertSame(3, AuditLog::query()->where('action', 'commercial.kpi_target_saved')->count());

        // the boxes show the year's targets; an unchanged box saves nothing; another year adds a row
        $page->assertSet('grid.'.$this->mamprobi->id.'.sales', '125976')->call('save');
        $this->assertSame(3, CommercialDistrictTarget::query()->count());

        $page->set('year', '2027')->set('grid.'.$this->mamprobi->id.'.sales', '140000')->call('save');
        $this->assertSame(4, CommercialDistrictTarget::query()->count());
        $this->assertSame(125976.0, CommercialDistrictTarget::query()->where('metric', 'sales')->whereDate('effective_from', '2026-01-01')->value('value'), 'the old year keeps its target');

        $audit = AuditLog::query()->where('action', 'commercial.kpi_target_saved')->latest('id')->first();
        $this->assertEquals(['old' => null, 'new' => 140000.0], ['old' => $audit->metadata['old'], 'new' => $audit->metadata['new']]);
    }

    public function test_a_bad_target_is_refused_and_nothing_of_that_save_is_kept_silently(): void
    {
        $page = Livewire::actingAs($this->superAdmin())->test(KpiTargets::class)->set('year', '2026')
            ->set('grid.'.$this->mamprobi->id.'.sales', '-5')->set('grid.'.$this->mamprobi->id.'.billing', 'plenty')->call('save');

        $page->assertHasErrors(['grid.'.$this->mamprobi->id.'.sales', 'grid.'.$this->mamprobi->id.'.billing']);
        $this->assertSame(0, CommercialDistrictTarget::query()->count());

        Livewire::actingAs($this->superAdmin())->test(KpiTargets::class)->set('year', '')->call('save')->assertHasErrors('year');
    }

    public function test_the_history_shows_every_row_and_a_row_can_be_removed(): void
    {
        $admin = $this->superAdmin();
        $this->targets($this->mamprobi, 100, 200, 300, '2025-01-01');
        $this->targets($this->mamprobi, 110, 210, 310, '2026-01-01');

        $page = Livewire::actingAs($admin)->test(KpiTargets::class)->set('historyDistrict', (string) $this->mamprobi->id)->assertSee('1,320.00')->assertSee('1,200.00');
        $row = CommercialDistrictTarget::query()->where('metric', 'sales')->whereDate('effective_from', '2026-01-01')->first();

        $page->call('delete', $row->id);

        $this->assertNull(CommercialDistrictTarget::query()->find($row->id));
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.kpi_target_deleted')->count());
    }

    public function test_someone_without_all_regions_can_only_set_the_targets_of_their_own_region(): void
    {
        $kumasi = $this->kumasi;
        $manager = $this->userWith('910520', ['commercial.view_billing', 'commercial.manage_settings'], $this->accraWest, $this->sowutuom);

        $page = Livewire::actingAs($manager)->test(KpiTargets::class)->assertSee('Mamprobi')->assertDontSee('Kumasi');

        // a request that names another region's district is refused
        $page->set('grid.'.$kumasi->id.'.sales', '5')->call('save')->assertForbidden();
        $this->assertSame(0, CommercialDistrictTarget::query()->count());

        $other = CommercialDistrictTarget::query()->create(['district_id' => $kumasi->id, 'metric' => 'sales', 'effective_from' => '2026-08-01', 'value' => 1]);
        Livewire::actingAs($manager)->test(KpiTargets::class)->call('delete', $other->id)->assertNotFound();
    }

    // ---------------------------------------------------------------- the export

    public function test_the_export_matches_the_page_and_needs_the_export_permission(): void
    {
        $this->sample();
        $this->targets($this->mamprobi, 125976, 1425674, 1425674);

        $response = $this->actingAs($this->officer())->get(route('commercial.export', ['report' => 'kpis', 'format' => 'excel']))->assertOk();
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $names = array_map(fn ($sheet) => $sheet->getTitle(), $book->getAllSheets());
        $text = json_encode(array_map(fn ($sheet) => $sheet->toArray(), $book->getAllSheets()));

        $this->assertNotSame([], $names);
        $missing = array_values(array_filter(['125976', '130072', '1515645', '103.3', '116.7', '105.8', '10171763', 'Mamprobi', 'Age of arrears'], fn ($needle) => ! str_contains($text.json_encode($names), $needle)));
        $this->assertSame([], $missing, 'missing from the workbook');

        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.export_kpis_excel')->count());
        $this->actingAs($this->officer())->get(route('commercial.export', ['report' => 'kpis', 'format' => 'pdf']))->assertOk();

        $viewer = $this->userWith('910530', ['commercial.view_billing'], $this->accraWest, $this->sowutuom);
        $this->actingAs($viewer)->get(route('commercial.export', ['report' => 'kpis', 'format' => 'excel']))->assertForbidden();
    }

    public function test_an_export_with_no_data_says_so_instead_of_failing(): void
    {
        $this->actingAs($this->officer())->from(route('commercial.kpis'))->get(route('commercial.export', ['report' => 'kpis', 'format' => 'excel']))->assertRedirect()->assertSessionHas('error');
    }

    public function test_the_credit_rule_is_stated_as_confirmed_everywhere_it_is_shown(): void
    {
        $this->month('2026-09-01', [$this->route('MAMPROBI', 1, 1000, 0, -50)]);

        Livewire::actingAs($this->officer())->test(\App\Livewire\Commercial\Billing::class)->set('tab', 'balances')->assertSee('A negative balance is a customer credit')->assertDontSee('to be confirmed');
        $this->assertSame(0, DB::table('commercial_import_batches')->where('status', 'failed')->count());
    }
}
