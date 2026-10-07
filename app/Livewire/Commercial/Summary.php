<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Permission;
use App\Services\Commercial\CommercialInsightsService;
use App\Services\Commercial\CommercialReportData;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The one-page executive summary (C3), plus the district scorecard (C1) and estimation against skip rate (C2) for users
 * who may see both billing and reading. Each section is computed only for the permissions the user holds.
 */
class Summary extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    /** Batch id of the billing snapshot; '' = the default (latest single month). */
    #[Url(as: 'snapshot')]
    public string $snapshot = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission('commercial.view_dashboard', 'commercial.view_billing', 'commercial.view_reading');
    }

    public function render(CommercialReportData $data)
    {
        $result = $data->summary($this->snapshot);
        // The chosen billing snapshot is `$chosen` in the view: `$snapshot` is this component's URL state (a string).
        $result['chosen'] = $result['snapshot'];
        unset($result['snapshot']);

        return view('livewire.commercial.summary', [
            ...$result,
            'canExport' => $this->actorCan('commercial.export_reports'),
            'canExportCombined' => $this->actorCan('commercial.export_reports') && $result['can_combine'],
            'canSeeBillingPage' => $this->actorCan('commercial.view_billing'),
            'canSeeReadingPage' => $this->actorCan('commercial.view_reading'),
            'canUpload' => $this->actorCan('commercial.upload_reports'),
            'likeForLikeNote' => $result['chosen'] && CommercialInsightsService::notLikeForLike($result['chosen']['segment'])
                ? CommercialInsightsService::notLikeForLikeNote($result['chosen'])
                : null,
        ]);
    }
}
