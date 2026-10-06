<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialImportBatch;
use App\Models\Permission;
use Livewire\Component;

/** Phase 1 overview: which reports have been loaded, and nothing analytical yet. */
class Home extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission(
            'commercial.view_dashboard',
            'commercial.view_billing',
            'commercial.view_reading',
            'commercial.upload_reports',
            'commercial.resolve_matches',
            'commercial.void_batches',
        );
    }

    public function render()
    {
        // Latest batch that still counts, per report type and region.
        $latest = $this->scopeBatchesForActor(CommercialImportBatch::query())
            ->notVoided()
            ->with('region')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (CommercialImportBatch $batch) => $batch->report_type.'|'.$batch->region_id)
            ->sortBy([['report_type', 'asc'], ['region_id', 'asc']])
            ->values();

        return view('livewire.commercial.home', [
            'latest' => $latest,
            'types' => CommercialImportBatch::TYPES,
            'canSeeUploads' => $this->actorCan('commercial.upload_reports')
                || $this->actorCan('commercial.resolve_matches')
                || $this->actorCan('commercial.void_batches'),
            'canUpload' => $this->actorCan('commercial.upload_reports'),
        ]);
    }
}
