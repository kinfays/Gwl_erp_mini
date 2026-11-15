<?php

namespace Tests\Feature\Commercial;

use App\Services\Commercial\RouteCcaResolver;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsRouteReading;

/**
 * Phase 8e, step 0.1: the CCA beside a route for a BILL PERIOD is the owner in the live reading-by-route upload whose period overlaps the bill
 * period most (ties: the newest), else the newest upload labelled "not the bill period", else nobody. One service, RouteCcaResolver, which the
 * Billing details screen and every cross-file screen use. Invented CCAs and routes only.
 *
 * The bill period of every case is 1 to 30 September 2026 (30 days).
 */
class RouteCcaResolverTest extends CustomerTestCase
{
    use BuildsRouteReading;

    /** One route, S1, owned by the CCA given. */
    protected function upload(string $from, string $to, string $cca): \App\Models\CommercialImportBatch
    {
        return $this->seedRouteReading([[$cca, 'CCA '.$cca, 'S1', $this->sowutuom, 100, 80, 10, 90]], ['from' => $from, 'to' => $to]);
    }

    protected function resolver(): RouteCcaResolver
    {
        $this->actingAs($this->superAdmin());

        return app(RouteCcaResolver::class);
    }

    public function test_the_upload_that_overlaps_the_bill_period_most_gives_the_cca(): void
    {
        $this->upload('2026-08-01', '2026-08-31', '80001');           // 0 days in common
        $best = $this->upload('2026-09-01', '2026-09-21', '80002');   // 21 days
        $this->upload('2026-09-15', '2026-10-31', '80003');           // 16 days (15 to 30 September)

        $found = $this->resolver()->forPeriod($this->accraWest->id, '2026-09-01', '2026-09-30');

        $this->assertSame($best->id, $found['batch_id']);
        $this->assertSame(RouteCcaResolver::OVERLAPS, $found['relation']);
        $this->assertSame(21, $found['overlap_days']);
        $this->assertFalse($found['tied']);
        $route = array_key_first($found['owners']);
        $this->assertSame('80002', $found['owners'][$route]['staff_id']);
        $this->assertStringContainsString('overlaps the bill period the most (21 days in common)', $found['sentence']);
        $this->assertStringContainsString('(batch #'.$best->id.')', $found['sentence']);
    }

    public function test_the_newest_upload_wins_a_tie_on_days_in_common(): void
    {
        $older = $this->upload('2026-09-01', '2026-09-10', '80001');   // 10 days
        $newer = $this->upload('2026-09-21', '2026-09-30', '80002');   // 10 days, and a later period
        $sameDays = $this->upload('2026-09-11', '2026-09-20', '80003');  // 10 days, in the middle

        $found = $this->resolver()->forPeriod($this->accraWest->id, '2026-09-01', '2026-09-30');

        $this->assertSame(10, $found['overlap_days']);
        $this->assertTrue($found['tied'], 'three uploads share the most days');
        $this->assertSame($newer->id, $found['batch_id'], 'the latest period end wins the tie');
        $this->assertNotSame($older->id, $found['batch_id']);
        $this->assertNotSame($sameDays->id, $found['batch_id']);

        // the same period uploaded twice: the later upload (id) wins
        $again = $this->upload('2026-09-21', '2026-09-30', '80009');
        $this->assertSame($again->id, $this->resolver()->forPeriod($this->accraWest->id, '2026-09-01', '2026-09-30')['batch_id']);
    }

    public function test_when_no_upload_overlaps_the_newest_is_used_and_labelled_not_the_bill_period(): void
    {
        $this->upload('2026-07-01', '2026-07-31', '80001');
        $newest = $this->upload('2026-10-01', '2026-10-31', '80002');

        $found = $this->resolver()->forPeriod($this->accraWest->id, '2026-09-01', '2026-09-30');

        $this->assertSame($newest->id, $found['batch_id']);
        $this->assertSame(RouteCcaResolver::NOT_BILL_PERIOD, $found['relation']);
        $this->assertSame(0, $found['overlap_days']);
        $this->assertStringContainsString('this is not the bill period', $found['sentence']);
        $this->assertSame('Oct 2026', $found['label']);
    }

    public function test_with_no_reading_upload_there_is_no_cca(): void
    {
        $this->assertNull($this->resolver()->forPeriod($this->accraWest->id, '2026-09-01', '2026-09-30'));

        // another region's upload is not this region's
        $this->seedRouteReading([['80001', 'CCA 80001', 'S1', $this->sowutuom, 10, 5, 1, 6]], ['region' => $this->ashanti, 'from' => '2026-09-01', 'to' => '2026-09-30']);
        $this->assertNull($this->resolver()->forPeriod($this->accraWest->id, '2026-09-01', '2026-09-30'));
    }

    public function test_a_voided_upload_is_never_used(): void
    {
        $voided = $this->upload('2026-09-01', '2026-09-30', '80001');
        $voided->forceFill(['status' => \App\Models\CommercialImportBatch::STATUS_VOIDED])->save();
        $live = $this->upload('2026-08-01', '2026-08-31', '80002');

        $found = $this->resolver()->forPeriod($this->accraWest->id, '2026-09-01', '2026-09-30');

        $this->assertSame($live->id, $found['batch_id']);
        $this->assertSame(RouteCcaResolver::NOT_BILL_PERIOD, $found['relation']);
    }

    public function test_a_district_user_is_only_given_the_owners_of_their_own_district(): void
    {
        $this->seedRouteReading([
            ['80001', 'AMA TEST', 'S1', $this->sowutuom, 100, 80, 10, 90],
            ['80003', 'EFUA TEST', 'O1', $this->odorkor, 100, 80, 10, 90],
        ], ['from' => '2026-09-01', 'to' => '2026-09-30']);

        $mine = $this->userWith('900040', ['commercial.view_billing', 'commercial.view_reader_performance'], $this->accraWest, $this->sowutuom, true);
        $this->actingAs($mine);
        $found = app(RouteCcaResolver::class)->forPeriod($this->accraWest->id, '2026-09-01', '2026-09-30');

        $this->assertSame(['80001'], array_values(array_column($found['owners'], 'staff_id')), 'the other district\'s owner is not returned');

        $all = $this->resolver()->forPeriod($this->accraWest->id, '2026-09-01', '2026-09-30');
        $this->assertCount(2, $all['owners']);
    }

    public function test_overlap_days_counts_both_ends(): void
    {
        $this->assertSame(1, RouteCcaResolver::overlapDays('2026-09-01', '2026-09-01', '2026-09-01', '2026-09-01'));
        $this->assertSame(30, RouteCcaResolver::overlapDays('2026-09-01', '2026-09-30', '2026-08-15', '2026-10-15'));
        $this->assertSame(0, RouteCcaResolver::overlapDays('2026-09-01', '2026-09-30', '2026-10-01', '2026-10-31'));
        $this->assertSame(1, RouteCcaResolver::overlapDays('2026-09-01', '2026-09-30', '2026-09-30', '2026-10-31'));
    }
}
