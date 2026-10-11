<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\District;
use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;
use App\Models\IctIssueCategory;
use App\Models\Region;
use App\Services\Assets\AssetRecordService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class IssueReports extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;
    use WithPagination;

    /** What the "still active?" popup can do to the asset. 'none' is the default and changes nothing. */
    public const PROMPT_CHOICES = ['none', IctAsset::STATUS_RETIRED, IctAsset::STATUS_LOST, IctAsset::STATUS_DAMAGED];

    public string $search = '';

    public string $status = '';

    public string $type = '';

    public int $perPage = 15;

    public bool $showForm = false;

    public ?int $editingReportId = null;

    public bool $regionLocked = false;

    /** The asset the popup is asking about. Locked, and re-resolved through the actor's scope when answered. */
    #[Locked]
    public ?int $statusPromptAssetId = null;

    public string $statusPromptChoice = 'none';

    public string $statusPromptReason = '';

    public array $form = [
        'title' => '',
        'issue_type' => '',
        'issue_subtype' => '',
        'reason' => '',
        'status' => IctAssetIssueReport::STATUS_OPEN,
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

    /** Changing the category clears what depended on it: the subtype, and a linked asset that no longer fits. */
    public function updatedForm($value, $key): void
    {
        if ($key !== 'issue_type') {
            return;
        }

        $this->form['issue_subtype'] = '';

        $assetId = (int) ($this->form['linked_asset_id'] ?? 0);

        if ($assetId && ! $this->assetPickerQuery()->whereKey($assetId)->exists()) {
            $this->form['linked_asset_id'] = null;
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
            'issue_subtype' => (string) $report->issue_subtype,
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
        $validated = $this->validate($this->rules(), $this->messages());
        $payload = $validated['form'];

        $category = $this->selectedCategory();

        // A category without subtypes never carries one; a blank select is stored as NULL.
        $payload['issue_subtype'] = $this->subtypeOptions() === [] ? null : (filled($payload['issue_subtype'] ?? null) ? $payload['issue_subtype'] : null);

        if ($this->regionLocked) {
            $payload['reporting_region_id'] = $this->actorRegionId();
        }

        if (! empty($payload['linked_asset_id'])) {
            // In the actor's region, and of the kind this category is about.
            if (! $this->assetPickerQuery()->whereKey((int) $payload['linked_asset_id'])->exists()) {
                throw ValidationException::withMessages([
                    'form.linked_asset_id' => $category?->maps_to_device_category
                        ? 'Pick a device that matches this type of issue.'
                        : 'Pick a device from your region.',
                ]);
            }
        }

        $promptAssetId = null;

        if ($this->editingReportId) {
            $report = $this->scopeReportsForActor(IctAssetIssueReport::query())->findOrFail($this->editingReportId);
            $previousStatus = (string) $report->status;

            $report->update([
                ...$payload,
                'reported_by_user_id' => auth()->id(),
            ]);

            // Only when a linked report moves INTO a closed state does the "still active?" question make sense.
            if (in_array($payload['status'], IctAssetIssueReport::CLOSED_STATUSES, true)
                && ! in_array($previousStatus, IctAssetIssueReport::CLOSED_STATUSES, true)
                && ! empty($payload['linked_asset_id'])
                && $this->canChangeAssetStatus()) {
                $promptAssetId = (int) $payload['linked_asset_id'];
            }

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

        if ($promptAssetId) {
            $this->statusPromptAssetId = $promptAssetId;
            $this->statusPromptChoice = 'none';
            $this->statusPromptReason = '';
        }
    }

    /** The popup's Skip action: nothing is changed anywhere. */
    public function skipStatusPrompt(): void
    {
        $this->dismissStatusPrompt();
    }

    /** Apply the popup's answer through AssetRecordService, so it is audit-logged and transfer-logged like any edit. */
    public function applyStatusPrompt(AssetRecordService $records): void
    {
        abort_unless($this->statusPromptAssetId, 422);

        $this->validate([
            'statusPromptChoice' => ['required', Rule::in(self::PROMPT_CHOICES)],
            'statusPromptReason' => [
                Rule::requiredIf(fn () => $this->statusPromptChoice === IctAsset::STATUS_DAMAGED),
                'nullable', 'string', 'max:1000',
            ],
        ], [
            'statusPromptReason.required' => 'Say what is wrong with it: a reason is required when marking a device Damaged.',
        ], [
            'statusPromptChoice' => 'status',
            'statusPromptReason' => 'reason',
        ]);

        if ($this->statusPromptChoice === 'none') {
            $this->dismissStatusPrompt();

            return;
        }

        abort_unless($this->canChangeAssetStatus(), 403);

        $asset = $this->scopeAssetsForActor(IctAsset::query())->findOrFail($this->statusPromptAssetId);

        $records->save([
            'status' => $this->statusPromptChoice,
            'status_reason' => filled($this->statusPromptReason) ? trim($this->statusPromptReason) : null,
        ], $asset->device_category, $asset);

        $this->dispatch('toast', type: 'success', message: $asset->asset_name.' marked '.$this->statusPromptChoice.'.');
        $this->dismissStatusPrompt();
    }

    protected function dismissStatusPrompt(): void
    {
        $this->statusPromptAssetId = null;
        $this->statusPromptChoice = 'none';
        $this->statusPromptReason = '';
        $this->resetErrorBag(['statusPromptChoice', 'statusPromptReason']);
    }

    /** The popup edits the asset itself, so it needs the same permission as the asset forms. */
    protected function canChangeAssetStatus(): bool
    {
        $user = $this->actor();

        return $user->hasRoles('super_admin') || $user->hasPermission('assets.edit');
    }

    protected function selectedCategory(): ?IctIssueCategory
    {
        $label = (string) ($this->form['issue_type'] ?? '');

        return $label === '' ? null : IctIssueCategory::query()->where('label', $label)->first();
    }

    /** The report being edited, if any (its stored values stay valid even if the option was later switched off). */
    protected function editedReport(): ?IctAssetIssueReport
    {
        return $this->editingReportId
            ? $this->scopeReportsForActor(IctAssetIssueReport::query())->find($this->editingReportId)
            : null;
    }

    /** @return array<int, string> labels of the active subtypes of the selected category */
    protected function subtypeOptions(): array
    {
        return $this->selectedCategory()?->activeSubtypes()->pluck('label')->all() ?? [];
    }

    /** Assets the actor may link: in their region, and narrowed to the selected category's device kind. */
    protected function assetPickerQuery(): Builder
    {
        $category = $this->selectedCategory();

        return $this->scopeAssetsForActor(IctAsset::query())
            ->when($category?->maps_to_device_category, fn (Builder $q, string $deviceCategory) => $q->where('device_category', $deviceCategory))
            ->when($category?->narrowedAssetTypes(), fn (Builder $q, array $types) => $q->whereIn('asset_type', $types));
    }

    protected function rules(): array
    {
        $existing = $this->editedReport();
        $category = $this->selectedCategory();
        $subtypes = $this->subtypeOptions();

        $types = $existing ? [...IctIssueCategory::activeLabels(), (string) $existing->issue_type] : IctIssueCategory::activeLabels();
        $statuses = $existing ? [...IctAssetIssueReport::STATUSES, (string) $existing->status] : IctAssetIssueReport::STATUSES;

        // A report keeps its stored subtype even if that option has since been switched off.
        if ($existing && $existing->issue_subtype && $existing->issue_type === ($this->form['issue_type'] ?? null)) {
            $subtypes[] = (string) $existing->issue_subtype;
        }

        return [
            'form.title' => ['required', 'string', 'max:255'],
            'form.issue_type' => ['required', Rule::in($types)],
            'form.issue_subtype' => $this->subtypeOptions() === []
                ? ['nullable', 'string', 'max:255']
                : ['required', 'string', Rule::in($subtypes)],
            'form.reason' => ['nullable', 'string'],
            'form.status' => ['required', Rule::in($statuses)],
            'form.linked_asset_id' => [$category?->requires_linked_asset ? 'required' : 'nullable', 'integer', 'exists:ict_assets,id'],
            'form.reporting_region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'form.reporting_district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'form.date_solved' => ['nullable', 'date'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.issue_type.in' => 'Pick one of the issue types in the list.',
            'form.issue_subtype.required' => 'Pick what is wrong, so recurring problems can be tracked.',
            'form.issue_subtype.in' => 'Pick one of the options in the list.',
            'form.linked_asset_id.required' => 'This type of issue must be linked to the affected device. Pick it from the list.',
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
            'issue_subtype' => '',
            'reason' => '',
            'status' => IctAssetIssueReport::STATUS_OPEN,
            'linked_asset_id' => null,
            'reporting_region_id' => $this->regionLocked ? $this->actorRegionId() : null,
            'reporting_district_id' => null,
            'date_solved' => null,
        ];
    }

    public function render()
    {
        $reports = $this->scopeReportsForViewing(
            IctAssetIssueReport::query()->with(['asset', 'region', 'district', 'reporter'])
        )
            ->when($this->search, function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term) {
                    $inner
                        ->where('title', 'like', $term)
                        ->orWhere('issue_type', 'like', $term)
                        ->orWhere('issue_subtype', 'like', $term)
                        ->orWhere('reason', 'like', $term)
                        ->orWhereHas('asset', fn ($assetQuery) => $assetQuery->where('asset_name', 'like', $term));
                });
            })
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->when($this->type, fn ($query) => $query->where('issue_type', $this->type))
            ->latest()
            ->paginate($this->perPage);

        // Filters cover every category ever offered plus anything already stored (old rows keep their old labels).
        $typeOptions = collect([...IctIssueCategory::allLabels(), ...IctAssetIssueReport::query()->distinct()->pluck('issue_type')->all()])
            ->filter()->unique()->values()->all();

        $statusOptions = collect([...IctAssetIssueReport::STATUSES, ...IctAssetIssueReport::query()->distinct()->pluck('status')->all()])
            ->filter()->unique()->values();

        $category = $this->showForm ? $this->selectedCategory() : null;

        $assets = $this->showForm
            ? $this->assetPickerQuery()->orderBy('asset_name')->get(['id', 'asset_name', 'serial_number'])
            : collect();

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
            'categoryOptions' => $this->showForm ? IctIssueCategory::activeLabels() : [],
            'subtypeOptions' => $this->showForm ? $this->subtypeOptions() : [],
            'requiresAsset' => (bool) $category?->requires_linked_asset,
            'statusOptions' => $statusOptions,
            'formStatuses' => IctAssetIssueReport::STATUSES,
            'assets' => $assets,
            'promptAsset' => $this->statusPromptAssetId
                ? $this->scopeAssetsForActor(IctAsset::query())->find($this->statusPromptAssetId, ['id', 'asset_name', 'serial_number', 'status'])
                : null,
            'promptChoices' => array_slice(self::PROMPT_CHOICES, 1),
            ...$this->regionViewData(),
            'regions' => $regions,
            'districts' => $districts,
        ]);
    }
}
