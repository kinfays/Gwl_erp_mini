<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AgentReport;
use App\Models\IctAsset;
use Livewire\Component;
use Livewire\WithPagination;

class AgentReports extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;
    use WithPagination;

    public string $search = '';

    public string $matchedFilter = 'all';

    public int $perPage = 20;

    public ?int $linkingReportId = null;

    public ?int $linkAssetId = null;

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'matchedFilter', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function startLink(int $reportId): void
    {
        $report = $this->scopeReportsForActor(AgentReport::query())->findOrFail($reportId);

        $this->linkingReportId = $report->id;
        $this->linkAssetId = $report->ict_asset_id;
    }

    public function cancelLink(): void
    {
        $this->linkingReportId = null;
        $this->linkAssetId = null;
    }

    public function linkReport(): void
    {
        $this->validate([
            'linkingReportId' => ['required', 'integer', 'exists:agent_reports,id'],
            'linkAssetId' => ['required', 'integer', 'exists:ict_assets,id'],
        ]);

        $report = $this->scopeReportsForActor(AgentReport::query())->findOrFail($this->linkingReportId);
        $asset = $this->scopeAssetsForActor(IctAsset::query())->findOrFail($this->linkAssetId);

        $report->update([
            'ict_asset_id' => $asset->id,
            'matched' => true,
            'linked_by_user_id' => auth()->id(),
            'linked_at' => now(),
        ]);

        $asset->update([
            'agent_last_report_at' => $report->reported_at ?: now(),
            'last_seen_at' => $report->reported_at ?: now(),
        ]);

        $this->dispatch('toast', type: 'success', message: 'Agent report linked successfully.');
        $this->cancelLink();
    }

    public function render()
    {
        $reports = $this->scopeReportsForActor(
            AgentReport::query()->with(['asset', 'user', 'region'])
        )
            ->when($this->search, function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term) {
                    $inner
                        ->where('hostname', 'like', $term)
                        ->orWhere('serial_number', 'like', $term)
                        ->orWhere('mac_address', 'like', $term)
                        ->orWhere('os_name', 'like', $term);
                });
            })
            ->when($this->matchedFilter === 'matched', fn ($query) => $query->where('matched', true))
            ->when($this->matchedFilter === 'unmatched', fn ($query) => $query->where('matched', false))
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->paginate($this->perPage);

        $assets = $this->scopeAssetsForActor(IctAsset::query())
            ->orderBy('asset_name')
            ->get(['id', 'asset_name', 'serial_number', 'hostname']);

        return view('livewire.assets.agent-reports', [
            'reports' => $reports,
            'assets' => $assets,
        ]);
    }
}

