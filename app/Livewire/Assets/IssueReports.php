<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\District;
use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;
use App\Models\Region;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class IssueReports extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $type = '';

    public int $perPage = 15;

    public bool $showForm = false;

    public ?int $editingReportId = null;

    public bool $regionLocked = false;

    public array $form = [
        'title' => '',
        'issue_type' => '',
        'reason' => '',
        'status' => 'Open',
        'linked_asset_id' => null,
        'reporting_region_id' => null,
        'reporting_district_id' => null,
        'date_solved' => null,
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');
        $this->regionLocked = $this->actorIsRegionScopedIct();

        if ($this->regionLocked) {
            $this->form['reporting_region_id'] = $this->actorRegionId();
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'type', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function openCreate(): void
    {
        $this->authorizeAction();
        $this->editingReportId = null;
        $this->showForm = true;
        $this->resetForm();
    }

    public function openEdit(int $id): void
    {
        $this->authorizeAction();

        $report = $this->scopeReportsForActor(IctAssetIssueReport::query())->findOrFail($id);

        $this->editingReportId = $report->id;
        $this->showForm = true;
        $this->form = [
            'title' => (string) $report->title,
            'issue_type' => (string) $report->issue_type,
            'reason' => (string) $report->reason,
            'status' => (string) $report->status,
            'linked_asset_id' => $report->linked_asset_id,
            'reporting_region_id' => $report->reporting_region_id,
            'reporting_district_id' => $report->reporting_district_id,
            'date_solved' => $report->date_solved?->toDateString(),
        ];
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingReportId = null;
        $this->resetForm();
    }

    public function save(): void
    {
        $this->authorizeAction();
        $validated = $this->validate($this->rules());
        $payload = $validated['form'];

        if ($this->regionLocked) {
            $payload['reporting_region_id'] = $this->actorRegionId();
        }

        if (! empty($payload['linked_asset_id'])) {
            $this->scopeAssetsForActor(IctAsset::query())->findOrFail((int) $payload['linked_asset_id']);
        }

        if ($this->editingReportId) {
            $report = $this->scopeReportsForActor(IctAssetIssueReport::query())->findOrFail($this->editingReportId);
            $report->update([
                ...$payload,
                'reported_by_user_id' => auth()->id(),
            ]);
            $message = 'Issue report updated.';
        } else {
            IctAssetIssueReport::query()->create([
                ...$payload,
                'reported_by_user_id' => auth()->id(),
            ]);
            $message = 'Issue report created.';
        }

        $this->dispatch('toast', type: 'success', message: $message);
        $this->closeForm();
    }

    protected function rules(): array
    {
        return [
            'form.title' => ['required', 'string', 'max:255'],
            'form.issue_type' => ['required', Rule::in(IctAssetIssueReport::ISSUE_TYPES)],
            'form.reason' => ['nullable', 'string'],
            'form.status' => ['required', 'string', 'max:120'],
            'form.linked_asset_id' => ['nullable', 'integer', 'exists:ict_assets,id'],
            'form.reporting_region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'form.reporting_district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'form.date_solved' => ['nullable', 'date'],
        ];
    }

    protected function authorizeAction(): void
    {
        $user = $this->actor();

        if (! $user->hasRoles('super_admin') && ! $user->hasPermission('assets.manage_reports')) {
            abort(403, 'You do not have permission to manage reports.');
        }
    }

    protected function resetForm(): void
    {
        $this->form = [
            'title' => '',
            'issue_type' => '',
            'reason' => '',
            'status' => 'Open',
            'linked_asset_id' => null,
            'reporting_region_id' => $this->regionLocked ? $this->actorRegionId() : null,
            'reporting_district_id' => null,
            'date_solved' => null,
        ];
    }

    public function render()
    {
        $reports = $this->scopeReportsForActor(
            IctAssetIssueReport::query()->with(['asset', 'region', 'district', 'reporter'])
        )
            ->when($this->search, function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term) {
                    $inner
                        ->where('title', 'like', $term)
                        ->orWhere('issue_type', 'like', $term)
                        ->orWhere('reason', 'like', $term)
                        ->orWhereHas('asset', fn ($assetQuery) => $assetQuery->where('asset_name', 'like', $term));
                });
            })
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->when($this->type, fn ($query) => $query->where('issue_type', $this->type))
            ->latest()
            ->paginate($this->perPage);

        $typeOptions = IctAssetIssueReport::ISSUE_TYPES;

        $statusOptions = IctAssetIssueReport::query()
            ->select('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');

        $assets = $this->scopeAssetsForActor(IctAsset::query())
            ->orderBy('asset_name')
            ->get(['id', 'asset_name', 'serial_number']);

        $regions = Region::query()
            ->when($this->regionLocked, fn ($query) => $query->whereKey($this->actorRegionId()))
            ->orderBy('region_name')
            ->get();

        $districts = District::query()
            ->when($this->regionLocked, fn ($query) => $query->where('region_id', $this->actorRegionId()))
            ->orderBy('district_name')
            ->get();

        return view('livewire.assets.issue-reports', [
            'reports' => $reports,
            'typeOptions' => $typeOptions,
            'statusOptions' => $statusOptions,
            'assets' => $assets,
            'regions' => $regions,
            'districts' => $districts,
        ]);
    }
}

