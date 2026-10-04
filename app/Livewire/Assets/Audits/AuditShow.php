<?php

namespace App\Livewire\Assets\Audits;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetAudit;
use App\Models\IctAssetAuditLine;
use App\Services\Assets\AssetAuditService;
use App\Services\Assets\AuditVisibility;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class AuditShow extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;
    use WithPagination;

    #[Locked]
    public int $auditId;

    public string $category = '';

    /** '' = all, 'pending', or an IctAssetAuditLine result. */
    public string $resultFilter = '';

    #[Locked]
    public ?int $recordingLineId = null;

    #[Locked]
    public ?string $recordingResult = null;

    public string $actualStatus = '';

    public int|string $actualEmployeeId = '';

    public int|string $actualDistrictId = '';

    public string $actualLocation = '';

    public string $reason = '';

    public function mount(IctAssetAudit $audit): void
    {
        $this->enforceLivewireModule('assets');
        $this->authorizeManage();

        abort_unless(AuditVisibility::canSee($this->actor(), $audit), 404);

        $this->auditId = $audit->id;
    }

    public function updating($name): void
    {
        if (in_array($name, ['category', 'resultFilter'], true)) {
            $this->resetPage();
        }
    }

    public function markMatched(int $lineId, AssetAuditService $service): void
    {
        $this->authorizeManage();

        $service->recordResult($this->line($lineId), IctAssetAuditLine::RESULT_MATCHED, [], null, (int) $this->actor()->id);

        $this->cancelRecord();
    }

    public function openRecord(int $lineId, string $result): void
    {
        $this->authorizeManage();
        abort_unless(in_array($result, [IctAssetAuditLine::RESULT_MISMATCH, IctAssetAuditLine::RESULT_NOT_FOUND], true), 422);

        $line = $this->line($lineId);

        $this->resetErrorBag();
        $this->recordingLineId = $line->id;
        $this->recordingResult = $result;
        // Start from what the system expects, so the verifier only changes what differs.
        $this->actualStatus = (string) ($line->actual_status ?? $line->expected_status);
        $this->actualEmployeeId = $line->actual_assigned_to_employee_id ?? $line->expected_assigned_to_employee_id ?? '';
        $this->actualDistrictId = $line->actual_district_id ?? $line->expected_district_id ?? '';
        $this->actualLocation = (string) $line->actual_location;
        $this->reason = (string) $line->mismatch_reason;
    }

    public function cancelRecord(): void
    {
        $this->reset('recordingLineId', 'recordingResult', 'actualStatus', 'actualEmployeeId', 'actualDistrictId', 'actualLocation', 'reason');
        $this->resetErrorBag();
    }

    public function saveRecord(AssetAuditService $service): void
    {
        $this->authorizeManage();
        abort_unless($this->recordingLineId && $this->recordingResult, 422);

        $notFound = $this->recordingResult === IctAssetAuditLine::RESULT_NOT_FOUND;

        $service->recordResult(
            $this->line($this->recordingLineId),
            $this->recordingResult,
            [
                // A missing asset has no status/holder/district to report, only where it was last known.
                'status' => $notFound ? null : $this->actualStatus,
                'assigned_to_employee_id' => $notFound ? null : $this->actualEmployeeId,
                'district_id' => $notFound ? null : $this->actualDistrictId,
                'location' => $this->actualLocation,
            ],
            $this->reason,
            (int) $this->actor()->id,
        );

        $this->cancelRecord();
    }

    public function applyCorrection(int $lineId, AssetAuditService $service): void
    {
        $this->authorizeManage();

        $line = $this->line($lineId);

        // Same write rule as editing the asset: a user may only change records in their own region.
        if (! $this->actorCanModifyInRegion($line->asset?->region_id)) {
            abort(403, 'You can only apply corrections to assets in your own region.');
        }

        $service->applyCorrection($line, (int) $this->actor()->id);

        $this->dispatch('toast', type: 'success', message: 'Correction applied to the asset record.');
    }

    public function completeAudit(AssetAuditService $service): void
    {
        $this->authorizeManage();

        $service->complete($this->audit());

        $this->dispatch('toast', type: 'success', message: 'Audit completed.');
    }

    protected function audit(): IctAssetAudit
    {
        return IctAssetAudit::query()->findOrFail($this->auditId);
    }

    protected function line(int $lineId): IctAssetAuditLine
    {
        return IctAssetAuditLine::query()
            ->where('ict_asset_audit_id', $this->auditId)
            ->with(['audit', 'asset'])
            ->findOrFail($lineId);
    }

    protected function authorizeManage(): void
    {
        $user = $this->actor();

        abort_unless($user->hasRoles('super_admin') || $user->hasPermission('assets.manage_audits'), 403);
    }

    public function render(AssetAuditService $service)
    {
        $audit = $this->audit()->load(['startedBy', 'region', 'district']);

        $lines = $audit->lines()
            ->with(['asset.assetModel', 'expectedEmployee', 'expectedDistrict', 'actualEmployee', 'actualDistrict'])
            ->when($this->category !== '', fn ($q) => $q->whereHas('asset', fn ($a) => $a->where('device_category', $this->category)))
            ->when($this->resultFilter === 'pending', fn ($q) => $q->whereNull('result'))
            ->when(in_array($this->resultFilter, IctAssetAuditLine::RESULTS, true), fn ($q) => $q->where('result', $this->resultFilter))
            ->orderBy('id')
            ->paginate(25);

        $recording = $this->recordingLineId;

        return view('livewire.assets.audits.audit-show', [
            'audit' => $audit,
            'summary' => $service->summary($audit),
            'lines' => $lines,
            'categories' => ['asset' => 'Assets', 'phone' => 'Phones', 'network' => 'Network'],
            'statusOptions' => IctAsset::STATUSES,
            'employees' => $recording
                ? Employee::query()
                    ->when($this->actorIsRegionScopedIct(), fn ($q) => $q->where('region_id', $this->actorRegionId()))
                    ->orderBy('full_name')->limit(500)->get()
                : collect(),
            'districts' => $recording ? District::query()->orderBy('district_name')->get() : collect(),
            'canExport' => $this->actor()->hasRoles('super_admin') || $this->actor()->hasPermission('assets.export_audits'),
            'preview' => $audit->isCompleted() ? $service->exportRows($audit)->take(200) : collect(),
            'columns' => $service->columns(),
        ]);
    }
}
