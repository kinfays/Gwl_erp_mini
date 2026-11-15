<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialImportBatch;
use App\Services\Commercial\CommercialReportData;
use Tests\Support\Commercial\BuildsRouteReading;

/**
 * Phase 8c: the five period rules (CommercialPeriods) as the reading report BY ROUTE applies them, exactly as billing does. One route
 * (S1) is read in every upload; the "visited" figure tells which uploads a view used. Invented data only.
 *
 *   1 identical period: the newest upload wins        2 a newer period that contains an older one shadows it
 *   3 partial overlap: both are kept, newest first    4 distinct periods add flows and take the latest strength (see ReadingRouteAnalyticsTest)
 *   5 only uploads INSIDE the range count; one partly outside is left out and listed
 */
class ReadingRoutePeriodRulesTest extends CommercialTestCase
{
    use BuildsRouteReading;

    /** One upload of route S1 with the given visits. */
    protected function upload(string $from, string $to, int $visited, int $strength = 100): CommercialImportBatch
    {
        return $this->seedRouteReading([['80001', 'AMA TEST', 'S1', $this->sowutuom, $strength, $visited, 0, $visited]], ['from' => $from, 'to' => $to]);
    }

    /** @return array<string, mixed> */
    protected function report(?string $from = null, ?string $to = null): array
    {
        $this->actingAs($this->superAdmin());

        return app(CommercialReportData::class)->routeReading(null, null, $from, $to);
    }

    protected function visited(array $report): int
    {
        return $report['analysis']['totals']['visited'];
    }

    public function test_the_default_is_the_latest_uploaded_period(): void
    {
        $this->upload('2026-10-01', '2026-10-15', 60);
        $this->upload('2026-10-16', '2026-10-31', 70);

        $r = $this->report();

        $this->assertSame(70, $this->visited($r));
        $this->assertSame('2026-10-16', $r['range']['from']);
        $this->assertSame('2026-10-31', $r['range']['to']);
        $this->assertFalse($r['range']['typed']);
        // the trend still shows both uploads, oldest first
        $this->assertSame([60, 70], array_column($r['trend'], 'visited'));
    }

    public function test_rule_1_an_identical_period_takes_the_newest_upload(): void
    {
        $old = $this->upload('2026-10-01', '2026-10-31', 10);
        $new = $this->upload('2026-10-01', '2026-10-31', 99);

        $r = $this->report('2026-10-01', '2026-10-31');

        $this->assertSame([$new->id], $r['range']['batch_ids']);
        $this->assertSame(99, $this->visited($r));
        $this->assertStringContainsString("Batch #{$old->id} is replaced by the newer batch #{$new->id} (the same period)", implode(' ', $r['notes']));
    }

    public function test_rule_2_a_newer_period_that_contains_an_older_one_shadows_it(): void
    {
        $old = $this->upload('2026-10-01', '2026-10-15', 10);
        $new = $this->upload('2026-10-01', '2026-10-31', 99);

        $r = $this->report('2026-10-01', '2026-10-31');

        $this->assertSame([$new->id], $r['range']['batch_ids']);
        $this->assertSame(99, $this->visited($r), 'nothing of the shadowed upload is added in');
        $this->assertStringContainsString("Batch #{$old->id} is replaced by the newer batch #{$new->id} (a period that contains it)", implode(' ', $r['notes']));

        $this->assertSame(10, (int) $old->routeStats()->sum('visited_count'), 'its rows stay on record');
    }

    public function test_rule_3_a_partial_overlap_keeps_both_uploads_and_a_view_uses_the_newest_first(): void
    {
        $old = $this->upload('2026-10-01', '2026-10-20', 10);
        $new = $this->upload('2026-10-15', '2026-10-31', 99);

        $r = $this->report('2026-10-01', '2026-10-31');

        $this->assertSame([$new->id], $r['range']['batch_ids']);
        $this->assertSame(99, $this->visited($r));
        $this->assertSame(17, $r['range']['covered_days']);                   // 15 to 31 October
        $this->assertSame(31, $r['range']['range_days']);
        $notes = implode(' ', $r['notes']);
        $this->assertStringContainsString("Batch #{$old->id} overlaps batch #{$new->id} and is left out of this view (both are kept)", $notes);
        $this->assertStringContainsString('No upload covers', $notes);        // the days 1 to 14 are said to be uncovered
        $this->assertSame(2, CommercialImportBatch::query()->notVoided()->count(), 'both are still stored');
    }

    public function test_rule_5_only_uploads_inside_the_range_count_and_one_partly_outside_is_listed(): void
    {
        $outside = $this->upload('2026-10-01', '2026-10-15', 10);
        $inside = $this->upload('2026-10-16', '2026-10-31', 70);

        $r = $this->report('2026-10-10', '2026-10-31');

        $this->assertSame([$inside->id], $r['range']['batch_ids']);
        $this->assertSame(70, $this->visited($r));
        $this->assertStringContainsString("#{$outside->id} (1 Oct to 15 Oct 2026)", implode(' ', $r['notes']));
        $this->assertStringContainsString('only partly inside the range', implode(' ', $r['notes']));

        // a range that holds no whole upload says so
        $none = $this->report('2026-10-02', '2026-10-05');
        $this->assertSame([], $none['range']['batch_ids']);
        $this->assertContains('No upload lies inside this range.', $none['notes']);
    }

    public function test_a_day_a_range_and_a_month_are_all_accepted_as_the_range(): void
    {
        $this->upload('2026-10-05', '2026-10-05', 5);          // one day
        $this->upload('2026-10-10', '2026-10-17', 8);          // a range

        $this->assertSame(5, $this->visited($this->report('2026-10-05', '2026-10-05')));
        $this->assertSame(8, $this->visited($this->report('2026-10-10', '2026-10-17')));
        $this->assertSame(13, $this->visited($this->report('2026-10')), 'a typed month is that whole month: both uploads, which do not overlap');
        $this->assertSame(13, $this->visited($this->report('2026-10-01', '2026-10-31')));
    }

    public function test_a_voided_upload_is_never_used(): void
    {
        $this->upload('2026-10-01', '2026-10-31', 10);
        $this->upload('2026-10-01', '2026-10-31', 99)->update(['status' => CommercialImportBatch::STATUS_VOIDED]);

        $this->assertSame(10, $this->visited($this->report('2026-10-01', '2026-10-31')));
    }
}
