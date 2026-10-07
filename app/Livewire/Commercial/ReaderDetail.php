<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialReadingStat;
use App\Models\Permission;
use App\Services\Commercial\ReadingAnalyticsService;
use Livewire\Component;

/**
 * One reader's months. Individual staff performance, so it needs commercial.view_reader_performance AND the reader's rows
 * must be in a region the user may see: a regional user asking for a reader of another region gets a 403.
 */
class ReaderDetail extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    public string $staffId;

    public function mount(string $staffId): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission('commercial.view_reader_performance');

        $this->staffId = $staffId;

        if ($this->rows()->isEmpty()) {
            // Rows exist but not in this user's regions: forbidden, not "unknown".
            $existsElsewhere = CommercialReadingStat::query()->effective()->readers()->where('reader_staff_id', $staffId)->exists();

            abort($existsElsewhere ? 403 : 404, $existsElsewhere ? 'This reader belongs to another region.' : 'No such reader.');
        }
    }

    protected function rows()
    {
        return $this->scopeReadingStatsForActor(CommercialReadingStat::query()->effective()->readers())
            ->where('reader_staff_id', $this->staffId)
            ->with(['employee:id,full_name,staff_id', 'district:id,district_name'])
            ->get();
    }

    public function render(ReadingAnalyticsService $analytics)
    {
        $rows = $this->rows();
        abort_if($rows->isEmpty(), 404);

        $detail = $analytics->readerMonths($rows, now());
        $months = $detail['months'];
        $visited = array_sum(array_column($months, 'visited'));
        $skipped = array_sum(array_column($months, 'skipped'));
        $labels = array_map(fn ($row) => $row['label'].($row['in_progress'] ? ' (in progress)' : ''), $months);

        return view('livewire.commercial.reader-detail', [
            'reader' => $detail['identity'],
            'months' => $months,
            'labels' => $labels,
            'totals' => [
                'visited' => $visited,
                'read' => array_sum(array_column($months, 'read')),
                'skipped' => $skipped,
                'skip_rate' => ReadingAnalyticsService::rate($skipped, $visited),
                'months_active' => count(array_filter($months, fn ($row) => $row['visited'] > 0)),
            ],
            'batchId' => $rows->max('batch_id'),
            'canResolve' => $this->actorCan('commercial.resolve_matches') || $this->actorCan('commercial.upload_reports'),
            'from' => request('from', ''),
            'to' => request('to', ''),
        ]);
    }
}
