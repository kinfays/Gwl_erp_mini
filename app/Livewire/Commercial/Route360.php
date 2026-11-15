<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Permission;
use App\Services\Commercial\Cross\CrossFileReport;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Route 360 (X2, Phase 8e, design 8.42): one route, everything the module knows about it, on one page. The page behind every route link in the
 * module. A route outside the viewer's reach (another district's, for a district user) or one that does not exist is a 404, never a 403, so the
 * id of another district's route is never confirmed. The data is CrossFileReport::route(), the same call the Excel and PDF export make.
 */
class Route360 extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    #[Locked]
    public int $routeId = 0;

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    #[Url]
    public string $mode = 'latest';

    public function mount(int $routeId, CrossFileReport $report): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission('commercial.view_reading', 'commercial.view_billing', 'commercial.view_customer_analytics');
        $this->routeId = $routeId;

        abort_if($report->route($routeId, $this->query()) === null, 404);
    }

    /** @return array{from: string, to: string, mode: string} */
    protected function query(): array
    {
        return ['from' => $this->dateFrom, 'to' => $this->dateTo, 'mode' => $this->mode === 'same' ? 'same' : 'latest'];
    }

    public function render(CrossFileReport $report)
    {
        $r = $report->route($this->routeId, $this->query());
        abort_if($r === null, 404);

        return view('livewire.commercial.route360', [
            'r' => $r, 'canExport' => $this->actorCan('commercial.export_reports'),
            'exportQuery' => array_filter(['from' => $this->dateFrom, 'to' => $this->dateTo, 'mode' => $this->mode === 'same' ? 'same' : null], fn ($v) => $v !== null && $v !== ''),
        ]);
    }
}
