<?php

namespace App\Services\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingRouteStat;
use Illuminate\Support\Carbon;

/**
 * THE CCA OF A ROUTE for a bill period: the one rule every screen that puts a CCA beside a billing figure uses (Phase 8e step 0.1, design 8.42).
 *
 * A route's CCA is the OWNER row (ReadingRouteOwner) of a live reading-by-route upload of the region. Which upload: the one whose period
 * OVERLAPS the bill period the most (days in common; ties go to the newest upload: the later period end, then the later id). When no upload
 * overlaps the bill period at all, the newest upload is used and the result is labelled "not the bill period". With no reading upload there
 * is no CCA (null).
 *
 * Scope is the one scoping trait: the owners returned are only those of rows the viewer may see (a district user's own district). Whether the
 * viewer may be shown a NAME (commercial.view_reader_performance) is the caller's decision, as it always was: this class only says who owns.
 * Nothing here writes anything.
 */
class RouteCcaResolver
{
    use ScopesCommercialByActor;

    public const OVERLAPS = 'overlaps';

    public const NOT_BILL_PERIOD = 'not_bill_period';

    /**
     * @param  string|null  $billFrom  first day of the bill period (Y-m-d), null when unknown
     * @param  string|null  $billTo  last day of the bill period (Y-m-d), null when unknown
     * @return array{owners: array<int, array{staff_id: string, name: string|null}>, batch_id: int, label: string, from: string, to: string, relation: string, overlap_days: int, tied: bool, sentence: string}|null
     */
    public function forPeriod(int $regionId, ?string $billFrom, ?string $billTo): ?array
    {
        $batch = $this->choose($regionId, $billFrom, $billTo);

        if ($batch === null) {
            return null;
        }

        $owners = [];

        $query = $this->scopeRouteStatsForActor(CommercialReadingRouteStat::query()->where('batch_id', $batch['batch']->id)->where('is_route_owner', true)->whereNotNull('route_id'));

        foreach ($query->toBase()->get(['route_id', 'cca_staff_id', 'cca_name_raw']) as $row) {
            $owners[(int) $row->route_id] = ['staff_id' => (string) $row->cca_staff_id, 'name' => $row->cca_name_raw];
        }

        $b = $batch['batch'];
        $label = CommercialPeriods::describe($b->period_from, $b->period_to);

        return [
            'owners' => $owners, 'batch_id' => (int) $b->id, 'label' => $label, 'from' => $b->period_from->toDateString(), 'to' => $b->period_to->toDateString(),
            'relation' => $batch['relation'], 'overlap_days' => $batch['overlap_days'], 'tied' => $batch['tied'],
            'sentence' => self::sentence($label, (int) $b->id, $batch['relation'], $batch['overlap_days']),
        ];
    }

    /**
     * The reading upload chosen for a bill period, without reading its rows (also what the tests of the rule use).
     *
     * @return array{batch: CommercialImportBatch, relation: string, overlap_days: int, tied: bool}|null
     */
    public function choose(int $regionId, ?string $billFrom, ?string $billTo): ?array
    {
        $batches = CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY)->live()->where('region_id', $regionId)->get();

        if ($batches->isEmpty()) {
            return null;
        }

        $scored = $batches->map(fn (CommercialImportBatch $b) => [
            'batch' => $b,
            'days' => $billFrom !== null && $billTo !== null ? self::overlapDays($billFrom, $billTo, $b->period_from->toDateString(), $b->period_to->toDateString()) : 0,
        ]);

        // the most days in common; then the newest upload (the later period end, then the later id)
        $ranked = $scored->sort(fn (array $x, array $y) => [$y['days'], $y['batch']->period_to->toDateString(), $y['batch']->id] <=> [$x['days'], $x['batch']->period_to->toDateString(), $x['batch']->id])->values();
        $best = $ranked->first();
        $tied = $best['days'] > 0 && $ranked->where('days', $best['days'])->count() > 1;

        return ['batch' => $best['batch'], 'relation' => $best['days'] > 0 ? self::OVERLAPS : self::NOT_BILL_PERIOD, 'overlap_days' => $best['days'], 'tied' => $tied];
    }

    /** Days two periods have in common (both ends inclusive), 0 when they do not touch. */
    public static function overlapDays(string $aFrom, string $aTo, string $bFrom, string $bTo): int
    {
        $start = max(Carbon::parse($aFrom)->startOfDay()->getTimestamp(), Carbon::parse($bFrom)->startOfDay()->getTimestamp());
        $end = min(Carbon::parse($aTo)->startOfDay()->getTimestamp(), Carbon::parse($bTo)->startOfDay()->getTimestamp());

        return $end < $start ? 0 : (int) round(($end - $start) / 86400) + 1;
    }

    /** The sentence a page, a PDF or an Excel note prints to say which upload the CCAs come from. */
    public static function sentence(string $label, int $batchId, string $relation, int $overlapDays): string
    {
        $base = 'the owner of that route in the reading report by route of '.$label.' (batch #'.$batchId.')';

        return $relation === self::OVERLAPS
            ? $base.', the upload that overlaps the bill period the most ('.$overlapDays.' day'.($overlapDays === 1 ? '' : 's').' in common)'
            : $base.': this is not the bill period (no reading upload overlaps it, so the newest one is used)';
    }
}
