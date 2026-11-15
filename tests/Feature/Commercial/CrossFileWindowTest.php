<?php

namespace Tests\Feature\Commercial;

use App\Services\Commercial\Cross\CrossFileReport;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsCrossSet;
use Tests\Support\Commercial\BuildsRouteReading;

/**
 * Phase 8e: THE WINDOW RULE. The three files are at different points in time (the set: reading October, billing September, customer list as at
 * 15 November), so every part says which period it used. By default each part uses its own newest upload; with a typed range, "latest of each" uses
 * each part's newest upload INSIDE the range and "same range" uses the typed range for every part; a part with no upload says so and is never invented.
 */
class CrossFileWindowTest extends CustomerTestCase
{
    use BuildsBillingDetails;
    use BuildsCrossSet;
    use BuildsRouteReading;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->setUpBillingDetails();
        $this->loadCrossSet();
        config(['gwl.commercial_min_visits_for_ranking' => 10, 'gwl.commercial_cross_min_route_customers' => 5]);
        $this->actingAs($this->crossAll());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    protected function build(string $key = 'x7', array $q = []): array
    {
        return app(CrossFileReport::class)->build($key, $q);
    }

    /** A small reading upload of the one route S1 for a period. */
    protected function readingUpload(string $from, string $to, int $visited = 6): int
    {
        return $this->seedRouteReading([['80001', 'AMA TEST', '4101', $this->sowutuom, 10, $visited - 1, 1, $visited]], ['from' => $from, 'to' => $to])->id;
    }

    public function test_without_a_range_each_part_uses_its_own_newest_upload_and_says_they_are_not_for_the_same_days(): void
    {
        $r = $this->build('x7');
        $w = $r['windows'];

        $this->assertSame('Oct 2026', $w['reading']['label']);
        $this->assertSame('Sep 2026', $w['billing']['label']);
        $this->assertSame('as at 15 Nov 2026', $w['customers']['label']);
        $this->assertSame([$this->sowutuom->id, $this->odorkor->id], collect($w['customers']['batch_ids'])->map(fn ($id) => (int) \App\Models\CommercialCustomerBatch::query()->whereKey($id)->value('district_id'))->all());
        $this->assertStringContainsString('NOT for the same days', implode(' ', $r['notes']));
        $this->assertStringContainsString('Reading by route and CCA: Oct 2026 (batch #', $w['reading']['sentence']);
    }

    public function test_the_newest_upload_overall_is_used_when_no_range_is_typed(): void
    {
        $older = $this->readingUpload('2026-08-01', '2026-08-31');
        $newer = $this->readingUpload('2026-11-20', '2026-11-30');

        $w = $this->build('x7')['windows']['reading'];

        $this->assertSame([$newer], $w['batch_ids'], 'the latest uploaded period, not the biggest nor the first');
        $this->assertNotContains($older, $w['batch_ids']);
    }

    public function test_latest_of_each_picks_the_newest_upload_inside_the_range_and_a_part_with_none_says_so(): void
    {
        $first = $this->readingUpload('2026-11-01', '2026-11-07');
        $second = $this->readingUpload('2026-11-08', '2026-11-14');

        $r = $this->build('x7', ['from' => '2026-11-01', 'to' => '2026-11-30', 'mode' => 'latest']);
        $w = $r['windows'];

        $this->assertSame([$second], $w['reading']['batch_ids'], 'each part uses its own newest upload inside the range');
        $this->assertNotContains($first, $w['reading']['batch_ids']);
        $this->assertTrue($w['customers']['available'], 'the list is dated 15 Nov, inside November');
        $this->assertFalse($w['billing']['available'], 'the billing upload is September');
        $this->assertStringContainsString('inside the range', $w['billing']['reason']);
        $this->assertNull($r['parts']['scorecard']['data']['rows'][0]['billing'] ?? null, 'no billing figure is made up');
        $this->assertStringContainsString('Each part uses its own newest upload inside the range', implode(' ', $r['notes']));
    }

    public function test_same_range_uses_the_typed_range_for_every_part_and_adds_the_uploads_inside_it(): void
    {
        $first = $this->readingUpload('2026-11-01', '2026-11-07');
        $second = $this->readingUpload('2026-11-08', '2026-11-14');

        $r = $this->build('x7', ['from' => '2026-11-01', 'to' => '2026-11-30', 'mode' => 'same']);

        $this->assertEqualsCanonicalizing([$first, $second], $r['windows']['reading']['batch_ids'], 'both uploads inside the range are added up, as on the reading screen');
        $this->assertSame('same', $r['mode']);
        $this->assertStringContainsString('the range you typed', implode(' ', $r['notes']));
    }

    public function test_a_range_with_no_upload_at_all_leaves_every_part_empty_with_its_reason(): void
    {
        $r = $this->build('x4', ['from' => '2026-01-01', 'to' => '2026-01-31']);

        foreach (['reading', 'billing', 'customers'] as $side) {
            $this->assertFalse($r['windows'][$side]['available'], $side);
            $this->assertNotEmpty($r['windows'][$side]['reason']);
        }

        $this->assertFalse($r['available']);
        $this->assertTrue($r['parts']['reconciliation']['empty']);
        $this->assertNull($r['parts']['reconciliation']['data']);
        $this->assertStringContainsString('inside the range', $r['parts']['reconciliation']['reason']);
    }

    public function test_the_two_modes_agree_when_one_upload_lies_inside_the_range(): void
    {
        $latest = $this->build('x3', ['from' => '2026-09-01', 'to' => '2026-10-31', 'mode' => 'latest']);
        $same = $this->build('x3', ['from' => '2026-09-01', 'to' => '2026-10-31', 'mode' => 'same']);

        $this->assertSame($latest['windows']['reading']['batch_ids'], $same['windows']['reading']['batch_ids']);
        $this->assertSame($latest['parts']['correlation']['data']['overall'], $same['parts']['correlation']['data']['overall']);
    }

    public function test_every_window_names_its_period_in_the_payload_the_exports_use(): void
    {
        $r = $this->build('x5');

        foreach (['billing', 'customers', 'link'] as $side) {
            $this->assertNotEmpty($r['windows'][$side]['sentence'], $side);
        }

        $this->assertStringContainsString('as linked to the list on 16 Nov 2026', $r['windows']['link']['sentence']);
        $this->assertArrayNotHasKey('reading', $r['windows'], 'X5 does not use the reading report, so it does not name it');
    }
}
