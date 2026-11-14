<?php

namespace App\Services\Commercial;

use App\Models\CommercialImportBatch;
use Illuminate\Support\Carbon;

/** What the two report importers share: the preview-array bookkeeping and the duplicate-file refusal. */
abstract class ReportImportService
{
    /** Issues listed in a preview before the rest are summarised; the count always covers all of them. */
    protected const ISSUE_LIST_CAP = 40;

    public function __construct(
        protected ReportFileReader $reader,
        protected LocationMatcher $locations,
        protected BatchLifecycleService $lifecycle,
    ) {
    }

    /** Staff IDs never imported (the billing system's own account): the Settings value, default 00000. @return list<string> */
    public static function excludedStaffIds(): array
    {
        return array_values(array_map('strval', (array) config('gwl.commercial_excluded_staff_ids', ['00000'])));
    }

    /** The excluded account: an ID on the list, or (whatever the ID) the name "System Administrator". */
    public static function isExcludedAccount(string $staffId, ?string $name): bool
    {
        return in_array($staffId, self::excludedStaffIds(), true) || (bool) preg_match('/system\s*administrator/i', (string) $name);
    }

    /** A route (or district) that fails its own checks is FLAGGED; the file is blocked only above this percentage of its routes. */
    protected function routeFailureLimitPct(): float
    {
        return max(0.0, (float) config('gwl.commercial_reconcile_max_route_failures_pct', 2.0));
    }

    /** True when $computed differs from the report's own grand total $reported by more than the grand-total tolerance (a percentage of it). */
    protected function beyondGrandTolerance(float $computed, float $reported): bool
    {
        return abs($computed - $reported) > abs($reported) * max(0.0, (float) config('gwl.commercial_reconcile_grand_total_tolerance_pct', 0.5)) / 100 + 1e-9;
    }

    protected function duplicateOf(string $hash): ?CommercialImportBatch
    {
        return CommercialImportBatch::query()->where('file_hash', $hash)->first();
    }

    protected function duplicateMessage(CommercialImportBatch $batch): string
    {
        return "This exact file was already uploaded as batch #{$batch->id} ({$batch->status}).";
    }

    /** @param  list<string>  $months  first-of-month dates, ascending */
    protected function monthRange(array $months): string
    {
        $first = Carbon::parse($months[0])->format('M Y');
        $last = Carbon::parse(end($months))->format('M Y');

        return $first === $last ? $first : "{$first} to {$last}";
    }

    /**
     * Adds the counts the screens and tests read, caps long issue lists (the totals still count every issue) and sets
     * `blocked`: any error blocks the import, a warning never does.
     */
    protected function finish(array $preview): array
    {
        foreach (['errors', 'warnings'] as $list) {
            $total = count($preview[$list]);

            if ($total > self::ISSUE_LIST_CAP) {
                $preview[$list] = [
                    ...array_slice($preview[$list], 0, self::ISSUE_LIST_CAP),
                    ['row' => '...', 'message' => 'and '.($total - self::ISSUE_LIST_CAP).' more.'],
                ];
            }

            $preview[$list.'_total'] = $total;
        }

        $preview['error_count'] = $preview['errors_total'];
        $preview['warning_count'] = $preview['warnings_total'];
        $preview['blocked'] = $preview['errors_total'] > 0;
        $preview['parsed']['attributes']['warning_count'] = $preview['warnings_total'];

        return $preview;
    }
}
