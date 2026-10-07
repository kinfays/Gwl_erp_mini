<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\Reading;
use App\Livewire\Commercial\Summary;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Commercial\BillingAnalyticsService;
use App\Services\Commercial\CommercialInsightsService;
use App\Services\Commercial\CommercialSettings;
use App\Services\Commercial\ReadingAnalyticsService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * A district of one reader is that person's own figures. A user who may not see individual readers
 * (commercial.view_reader_performance) sees no district-grouped reading figure below the configured number of readers,
 * on the page and in the file. "Today" is 15 Oct 2026.
 *
 * September reading: Sowutuom has ONE reader (777 visits), Odorkor three (100 + 200 + 300), Head Office two (50 + 40) and
 * one reader (10 visits) is not in the directory.
 */
class SmallGroupProtectionTest extends CommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->seedReading([
            'R1' => ['2026-09-01' => [700, 77]],
            'R3' => ['2026-09-01' => [90, 10]], 'R4' => ['2026-09-01' => [150, 50]], 'R5' => ['2026-09-01' => [250, 50]],
            'R6' => ['2026-09-01' => [40, 10]], 'R7' => ['2026-09-01' => [30, 10]],
            'R8' => ['2026-09-01' => [10, 0]],
        ], options: ['strength' => 10000, 'status' => ['R8' => 'unmatched'], 'districts' => [
            'R1' => $this->sowutuom->id, 'R3' => $this->odorkor->id, 'R4' => $this->odorkor->id, 'R5' => $this->odorkor->id,
            'R6' => $this->headOffice->id, 'R7' => $this->headOffice->id,
        ]]);

        $this->seedBilling([
            ['district' => 'SOWUTUOM', 'code' => 'S1', 'billing_for_period' => 1000, 'total_payments' => 800, 'volume_total' => 100, 'volume_average' => 40, 'billed_total' => 8, 'unbilled_total' => 2],
            ['district' => 'ODORKOR', 'code' => 'O1', 'billing_for_period' => 500, 'total_payments' => 250, 'volume_total' => 50, 'volume_average' => 0, 'billed_total' => 5, 'unbilled_total' => 0],
        ], '2026-09-01', ['districts' => ['SOWUTUOM' => $this->sowutuom->id, 'ODORKOR' => $this->odorkor->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Sees billing and reading (and may export) but NOT individual readers. */
    protected function aggregateUser(): User
    {
        return $this->customUser('900950', ['commercial.view_billing', 'commercial.view_reading', 'commercial.export_reports']);
    }

    protected function customUser(string $staffId, array $permissions): User
    {
        $role = Role::query()->create(['name' => 'custom_'.$staffId, 'display_name' => 'Custom '.$staffId, 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL, 'can_access' => true]);

        $user = $this->userWithRoles($staffId, []);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    protected function sheet(TestResponse $response, string $title): array
    {
        return IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheetByName($title)->toArray(null, false, false, false);
    }

    protected function scorecardRows(User $user): \Illuminate\Support\Collection
    {
        $response = $this->actingAs($user)->get(route('commercial.export', ['report' => 'scorecard', 'format' => 'excel']))->assertOk();

        return collect($this->sheet($response, 'District scorecard'))->slice(1)->keyBy(0);
    }

    // ---------------------------------------------------------------- the service (C1)

    public function test_the_scorecard_service_withholds_small_districts_and_never_the_billing_columns(): void
    {
        $billing = new BillingAnalyticsService;
        $insights = new CommercialInsightsService(new ReadingAnalyticsService, $billing);
        $batch = \App\Models\CommercialImportBatch::query()->where('report_type', 'billing_summary')->firstOrFail();
        $routes = \App\Models\CommercialBillingRoute::query()->where('batch_id', $batch->id)->get();
        $stats = \App\Models\CommercialReadingStat::query()->effective()->readers()->with('district')->get();

        $hidden = collect($insights->districtScorecard($billing->describe($batch), $routes, $stats, now(), true, 3)['rows'])->keyBy(fn ($r) => $r['key'] !== '' ? $r['key'] : $r['district']);

        $sowutuom = $hidden['SOWUTUOM'];
        $this->assertTrue($sowutuom['reading_hidden']);
        $this->assertTrue($sowutuom['reading_side'], 'there is reading data; it is just withheld');
        $this->assertSame([null, null, null, null, null], [$sowutuom['visits'], $sowutuom['read'], $sowutuom['skip_rate'], $sowutuom['active_readers'], $sowutuom['visits_per_reader']]);
        $this->assertSame([1000.0, 80.0], [$sowutuom['billing'], $sowutuom['cash_ratio']], 'billing has no staff in it and is never held back');

        $this->assertFalse($hidden['ODORKOR']['reading_hidden']);
        $this->assertSame([600, 3, 200.0], [$hidden['ODORKOR']['visits'], $hidden['ODORKOR']['active_readers'], $hidden['ODORKOR']['visits_per_reader']]);

        $this->assertTrue($hidden['Head Office']['reading_hidden'], 'two readers is below three');
        $this->assertTrue($hidden['No home district (reader not in the directory)']['reading_hidden'], 'the no-home-district row follows the same rule');

        $shown = collect($insights->districtScorecard($billing->describe($batch), $routes, $stats, now(), false, 3)['rows'])->keyBy(fn ($r) => $r['key'] !== '' ? $r['key'] : $r['district']);
        $this->assertSame(777, $shown['SOWUTUOM']['visits']);
        $this->assertFalse($shown['SOWUTUOM']['reading_hidden']);
        $this->assertSame(10, $shown['No home district (reader not in the directory)']['visits']);
    }

    // ---------------------------------------------------------------- the page and the file agree

    public function test_a_user_without_reader_access_sees_neither_the_figures_nor_a_file_with_them(): void
    {
        $user = $this->aggregateUser();

        Livewire::actingAs($user)->test(Summary::class)
            ->assertSee('District scorecard')
            ->assertSee('Fewer than 3 readers')
            ->assertSee('600')                       // Odorkor: three readers
            ->assertDontSee('777')                   // Sowutuom: one reader
            ->assertSee("A district's reading figures are left out when fewer than 3 readers had visits", false);

        $rows = $this->scorecardRows($user);
        $this->assertNull($rows['Sowutuom'][6]);
        $this->assertNull($rows['Sowutuom'][8]);
        $this->assertSame('Fewer than 3 readers', $rows['Sowutuom'][11]);
        $this->assertEquals(1000, $rows['Sowutuom'][1], 'billing is still there');
        $this->assertEquals(600, $rows['Odorkor'][6]);
        $this->assertNull($rows['Odorkor'][11]);
        $this->assertSame('Fewer than 3 readers', $rows['Head Office'][11]);
        $this->assertSame('Fewer than 3 readers', $rows['No home district (reader not in the directory)'][11]);
    }

    public function test_a_user_with_reader_access_sees_everything_as_before(): void
    {
        $officer = $this->officer();   // holds view_reader_performance

        Livewire::actingAs($officer)->test(Summary::class)->assertSee('777')->assertDontSee('Fewer than 3 readers');

        $rows = $this->scorecardRows($officer);
        $this->assertEquals(777, $rows['Sowutuom'][6]);
        $this->assertNull($rows['Sowutuom'][11]);
        $this->assertEquals(90, $rows['Head Office'][6]);
        $this->assertEquals(10, $rows['No home district (reader not in the directory)'][6]);
    }

    public function test_the_threshold_follows_the_setting(): void
    {
        $user = $this->aggregateUser();
        $settings = app(CommercialSettings::class);

        $settings->save(['commercial_min_readers_for_district_figures' => 1], $this->superAdmin()->id);
        $this->assertEquals(777, $this->scorecardRows($user)['Sowutuom'][6], 'one reader is enough when the minimum is 1');

        $settings->save(['commercial_min_readers_for_district_figures' => 4], $this->superAdmin()->id);
        $rows = $this->scorecardRows($user);
        $this->assertSame('Fewer than 4 readers', $rows['Odorkor'][11], 'three readers is now too few');
        $this->assertNull($rows['Odorkor'][6]);
    }

    // ---------------------------------------------------------------- the Reading trend's district filter

    public function test_the_reading_trend_for_one_small_district_is_an_empty_state_for_a_user_without_reader_access(): void
    {
        $user = $this->aggregateUser();

        Livewire::actingAs($user)->test(Reading::class)
            ->set('district', (string) $this->sowutuom->id)
            ->assertSee('Too few readers to show this district')
            ->assertSee('fewer than 3 readers')
            ->assertDontSee('777')
            ->assertDontSee('Month by month');

        // Three readers is enough.
        Livewire::actingAs($user)->test(Reading::class)->set('district', (string) $this->odorkor->id)->assertSee('Month by month')->assertSee('600')->assertDontSee('Too few readers');

        // No district filter: the whole region, as ever.
        Livewire::actingAs($user)->test(Reading::class)->assertSee('Month by month')->assertDontSee('Too few readers');

        // With reader access the small district is shown.
        Livewire::actingAs($this->officer())->test(Reading::class)->set('district', (string) $this->sowutuom->id)->assertSee('Month by month')->assertSee('777')->assertDontSee('Too few readers');
    }

    public function test_the_reading_trend_export_for_a_small_district_holds_back_the_figures_too(): void
    {
        $query = ['report' => 'reading-trend', 'format' => 'excel', 'district' => $this->sowutuom->id];

        $held = $this->actingAs($this->aggregateUser())->get(route('commercial.export', $query))->assertOk();
        $book = IOFactory::load($held->baseResponse->getFile()->getPathname());

        $this->assertCount(1, $book->getSheetByName('Month by month')->toArray(), 'a heading row and nothing else');
        $this->assertStringContainsString('fewer than 3 readers', json_encode($book->getSheetByName('Notes')->toArray()));
        $this->assertStringNotContainsString('777', json_encode($book->getSheetByName('Month by month')->toArray()));

        $open = $this->actingAs($this->officer())->get(route('commercial.export', $query))->assertOk();
        $rows = IOFactory::load($open->baseResponse->getFile()->getPathname())->getSheetByName('Month by month')->toArray(null, false, false, false);
        $this->assertCount(2, $rows);
        $this->assertEquals(777, $rows[1][2]);
    }

    public function test_the_dashboard_viewer_without_reader_access_is_held_to_the_rule_too(): void
    {
        // view_dashboard alone shows region-wide reading numbers; it never reaches a district view. The Reading page needs more.
        $dashboard = $this->customUser('900951', ['commercial.view_dashboard']);

        $this->actingAs($dashboard)->get(route('commercial.reading'))->assertForbidden();
    }
}
