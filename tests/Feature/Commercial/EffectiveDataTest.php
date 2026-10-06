<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\BatchShow;
use App\Models\AuditLog;
use App\Models\CommercialBillingBand;
use App\Models\CommercialBillingRoute;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingStat;
use App\Models\CommercialReadingStrength;
use App\Models\Region;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

class EffectiveDataTest extends CommercialTestCase
{
    // ---------------------------------------------------------------- reading

    public function test_the_newer_batch_wins_for_a_month_while_earlier_months_keep_their_latest_value(): void
    {
        $monthly = $this->readingBatch(['2026-06-01' => ['15071' => [400, 100]], '2026-07-01' => ['15071' => [300, 100]]]);
        $weekly = $this->readingBatch(['2026-07-01' => ['15071' => [350, 120]]]);

        $effective = CommercialReadingStat::query()->effective()->orderBy('month')->get();

        $this->assertCount(2, $effective);
        $this->assertSame([$monthly->id, $weekly->id], $effective->pluck('batch_id')->all(), 'June still from the monthly file, July refreshed by the weekly one');
        $this->assertSame(350, $effective[1]->read_count);
    }

    public function test_a_voided_batch_is_ignored_and_the_one_it_replaced_counts_again(): void
    {
        $older = $this->readingBatch(['2026-06-01' => ['15071' => [400, 100]]]);
        $newer = $this->readingBatch(['2026-06-01' => ['15071' => [999, 1]]]);

        $this->assertSame([$newer->id], CommercialReadingStat::query()->effective()->pluck('batch_id')->all());

        $newer->update(['status' => CommercialImportBatch::STATUS_VOIDED]);

        $this->assertSame([$older->id], CommercialReadingStat::query()->effective()->pluck('batch_id')->all());
        $this->assertSame([], CommercialReadingStat::query()->where('batch_id', $newer->id)->effective()->pluck('id')->all());
    }

    public function test_a_reader_missing_from_the_newer_batch_keeps_their_older_row(): void
    {
        $this->readingBatch(['2026-06-01' => ['15071' => [400, 100], '15072' => [300, 50]]]);
        $newer = $this->readingBatch(['2026-06-01' => ['15071' => [410, 90]]]);

        $effective = CommercialReadingStat::query()->effective()->orderBy('reader_staff_id')->get();

        $this->assertSame(['15071', '15072'], $effective->pluck('reader_staff_id')->all());
        $this->assertSame($newer->id, $effective[0]->batch_id);
        $this->assertNotSame($newer->id, $effective[1]->batch_id);
    }

    public function test_another_regions_batch_never_replaces_this_regions_rows(): void
    {
        $accra = $this->readingBatch(['2026-06-01' => ['15071' => [400, 100]]]);
        $this->readingBatch(['2026-06-01' => ['15071' => [5, 5]]], $this->ashanti);

        $this->assertCount(2, CommercialReadingStat::query()->effective()->get());
        $this->assertSame(400, CommercialReadingStat::query()->effective()->where('batch_id', $accra->id)->value('read_count'));
    }

    public function test_strengths_follow_the_same_rule(): void
    {
        $older = $this->readingBatch(['2026-06-01' => ['15071' => [1, 1]]], strength: 59000);
        $newer = $this->readingBatch(['2026-06-01' => ['15071' => [1, 1]]], strength: 59500);

        $this->assertSame([59500], CommercialReadingStrength::query()->effective()->pluck('verified_strength')->all());

        $newer->update(['status' => CommercialImportBatch::STATUS_VOIDED]);

        $this->assertSame([59000], CommercialReadingStrength::query()->effective()->pluck('verified_strength')->all());
        $this->assertNotNull($older);
    }

    // ---------------------------------------------------------------- billing

    public function test_the_newer_billing_snapshot_wins_only_for_the_same_region_segment_and_period(): void
    {
        $june = $this->billingBatch('2026-06-01', 'new_service', 100);
        $juneNewer = $this->billingBatch('2026-06-01', 'new_service', 200);
        $july = $this->billingBatch('2026-07-01', 'new_service', 300);
        $juneAll = $this->billingBatch('2026-06-01', 'all', 400);
        $ashanti = $this->billingBatch('2026-06-01', 'new_service', 500, $this->ashanti);

        $effective = CommercialBillingRoute::query()->effective()->orderBy('batch_id')->pluck('batch_id')->all();

        $this->assertSame([$juneNewer->id, $july->id, $juneAll->id, $ashanti->id], $effective);
        $this->assertNotContains($june->id, $effective);
        $this->assertSame([$juneNewer->id, $july->id, $juneAll->id, $ashanti->id], CommercialBillingBand::query()->effective()->orderBy('batch_id')->pluck('batch_id')->all());

        $juneNewer->update(['status' => CommercialImportBatch::STATUS_VOIDED]);

        $this->assertContains($june->id, CommercialBillingRoute::query()->effective()->pluck('batch_id')->all(), 'voiding the replacement brings the earlier snapshot back');
        $this->assertNotContains($juneNewer->id, CommercialBillingRoute::query()->effective()->pluck('batch_id')->all());
    }

    // ---------------------------------------------------------------- statuses, through the real import and void flows

    public function test_importing_a_file_that_covers_an_earlier_one_marks_it_superseded_and_voiding_restores_it(): void
    {
        $officer = $this->officer();
        $this->actingAs($officer);

        $first = $this->importFile($this->billingFile());
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $first->status);

        $districts = ReportWorkbooks::defaultDistricts();
        $districts['SOWUTUOM'][0]['billing'] = 123.0;
        $replacement = $this->importFile($this->billingFile(['districts' => $districts]));

        $this->assertSame(CommercialImportBatch::STATUS_SUPERSEDED, $first->fresh()->status);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $replacement->status);
        $this->assertSame($first->id, $replacement->supersedes_batch_id);

        $log = AuditLog::query()->where('action', 'commercial.batch_superseded')->sole();
        $this->assertSame($first->id, $log->target_id);
        $this->assertSame($replacement->id, $log->metadata['superseded_by_batch_id']);

        $this->assertSame([$replacement->id], CommercialBillingRoute::query()->effective()->pluck('batch_id')->unique()->values()->all());

        Livewire::test(BatchShow::class, ['batch' => $replacement])
            ->call('startVoid')
            ->call('voidBatch')
            ->assertHasErrors('voidReason')
            ->set('voidReason', 'Wrong adjustment figure in this export')
            ->call('voidBatch')
            ->assertHasNoErrors();

        $replacement->refresh();
        $this->assertSame(CommercialImportBatch::STATUS_VOIDED, $replacement->status);
        $this->assertSame($officer->id, $replacement->voided_by);
        $this->assertSame('Wrong adjustment figure in this export', $replacement->void_reason);
        $this->assertNotNull($replacement->voided_at);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $first->fresh()->status, 'the voided batch gives its place back');
        $this->assertSame([$first->id], CommercialBillingRoute::query()->effective()->pluck('batch_id')->unique()->values()->all());

        $voided = AuditLog::query()->where('action', 'commercial.batch_voided')->sole();
        $this->assertSame($replacement->id, $voided->target_id);
        $this->assertSame('Wrong adjustment figure in this export', $voided->metadata['reason']);
        $this->assertSame([$first->id], $voided->metadata['restored_batch_ids']);
    }

    public function test_a_reading_batch_is_superseded_only_when_none_of_its_rows_is_still_effective(): void
    {
        $this->employee('15071', 'Kofi Mensah');
        $this->actingAs($this->officer());

        $full = $this->importFile($this->readingFile(['months' => ['2026-06-01', '2026-07-01']]));

        // A weekly file for July alone refreshes July: June of the first file is still the latest June.
        $july = $this->importFile($this->readingFile(['months' => ['2026-07-01'], 'readers' => [
            ['id' => '15071', 'name' => 'KOFI MENSAH', 'counts' => ['2026-07-01' => [450, 60]]],
        ]]));

        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $full->fresh()->status, 'June of the first file still counts');
        $this->assertNull($july->supersedes_batch_id);

        // A file covering both months again leaves nothing of the first one effective.
        $both = $this->importFile($this->readingFile(['months' => ['2026-06-01', '2026-07-01'], 'readers' => [
            ['id' => '15071', 'name' => 'KOFI MENSAH', 'counts' => ['2026-06-01' => [1, 1], '2026-07-01' => [2, 2]]],
            ['id' => '15072', 'name' => 'AMA SERWAA', 'counts' => ['2026-06-01' => [3, 3], '2026-07-01' => [4, 4]]],
            ['id' => '00000', 'name' => 'System Administrator', 'counts' => ['2026-06-01' => [5, 5], '2026-07-01' => [6, 6]]],
        ]]));

        $this->assertSame(CommercialImportBatch::STATUS_SUPERSEDED, $full->fresh()->status);
        $this->assertSame(CommercialImportBatch::STATUS_SUPERSEDED, $july->fresh()->status);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $both->status);
        $this->assertSame($july->id, $both->supersedes_batch_id, 'the most recent batch it replaced');
        $this->assertSame(6, CommercialReadingStat::query()->effective()->count());
    }

    public function test_the_preview_says_how_many_rows_an_upload_will_replace(): void
    {
        $this->actingAs($this->officer());

        $this->importFile($this->readingFile());

        $changed = $this->readingFile(['readers' => [
            ['id' => '15071', 'name' => 'KOFI MENSAH', 'counts' => ['2026-06-01' => [1, 1], '2026-07-01' => [2, 2]]],
        ]]);

        $preview = $this->previewOf($changed);

        $this->assertSame(6, $preview['will_replace'], 'two months of the three accounts already loaded (two readers and the system account)');
        $this->assertStringContainsString('will be replaced by this file', collect($preview['warnings'])->pluck('message')->implode(' '));
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * @param  array<string, array<string, array{0: int, 1: int}>>  $months  month => staff id => [read, skipped]
     */
    protected function readingBatch(array $months, ?Region $region = null, int $strength = 59000): CommercialImportBatch
    {
        $region ??= $this->accraWest;
        $dates = array_keys($months);

        $batch = CommercialImportBatch::query()->create([
            'report_type' => CommercialImportBatch::TYPE_READING_SUMMARY,
            'region_id' => $region->id,
            'period_from' => min($dates),
            'period_to' => max($dates),
            'granularity' => CommercialImportBatch::GRANULARITY_MONTHLY,
            'source_filename' => 'reading.xlsx',
            'file_hash' => hash('sha256', uniqid('reading', true)),
            'status' => CommercialImportBatch::STATUS_IMPORTED,
            'imported_at' => now(),
        ]);

        foreach ($months as $month => $readers) {
            $batch->strengths()->create(['month' => $month, 'verified_strength' => $strength]);

            foreach ($readers as $staffId => [$read, $skipped]) {
                $batch->stats()->create([
                    'month' => $month,
                    'reader_staff_id' => $staffId,
                    'read_count' => $read,
                    'skipped_count' => $skipped,
                    'visited_count' => $read + $skipped,
                    'match_status' => CommercialReadingStat::MATCH_MATCHED,
                ]);
            }
        }

        return $batch;
    }

    protected function billingBatch(string $month, string $segment, float $billing, ?Region $region = null): CommercialImportBatch
    {
        $region ??= $this->accraWest;

        $batch = CommercialImportBatch::query()->create([
            'report_type' => CommercialImportBatch::TYPE_BILLING_SUMMARY,
            'region_id' => $region->id,
            'period_from' => $month,
            'period_to' => date('Y-m-t', strtotime($month)),
            'granularity' => CommercialImportBatch::GRANULARITY_MONTHLY,
            'customer_segment' => $segment,
            'source_filename' => 'billing.xlsx',
            'file_hash' => hash('sha256', uniqid('billing', true)),
            'status' => CommercialImportBatch::STATUS_IMPORTED,
            'imported_at' => now(),
        ]);

        $batch->routes()->create(['district_label_raw' => 'SOWUTUOM', 'route_code' => 'SOWUTUOM 1', 'billing_for_period' => $billing]);
        $batch->bands()->create(['category_code' => '611', 'band' => '<=5', 'customers' => 1, 'volume' => 1, 'amount' => 1]);

        return $batch;
    }
}
