<?php

namespace App\Livewire\Assets\Settings;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\IctIssueCategory;
use App\Models\IctIssueSubtype;
use App\Models\Permission;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Issue categories and their subtypes, as shown on the Issue Reporting form. Reports store the label as text, so
 * labels are never renamed or deleted here: switch an option off instead and it simply stops being offered.
 */
class IssueCategoriesManager extends Component
{
    use EnforcesModuleAccess;

    /** The category whose subtypes are being managed. */
    public ?int $selectedCategoryId = null;

    public string $newSubtype = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');

        abort_unless($this->canManage(), 403);

        $this->selectedCategoryId = IctIssueCategory::query()->ordered()->value('id');
    }

    public function selectCategory(int $categoryId): void
    {
        $this->authorizeManage();

        $this->selectedCategoryId = IctIssueCategory::query()->findOrFail($categoryId)->id;
        $this->reset('newSubtype');
        $this->resetErrorBag();
    }

    public function toggleCategory(int $categoryId): void
    {
        $this->authorizeManage();

        $category = IctIssueCategory::query()->findOrFail($categoryId);
        $old = $category->toArray();
        $category->update(['is_active' => ! $category->is_active]);

        $this->audit('update_issue_category', 'ict_issue_categories', $category->id, $old, $category->fresh()->toArray());
    }

    public function moveCategory(int $categoryId, string $direction): void
    {
        $this->authorizeManage();
        abort_unless(in_array($direction, ['up', 'down'], true), 422);

        $this->move(IctIssueCategory::query()->ordered()->get(), $categoryId, $direction, 'ict_issue_categories');
    }

    public function addSubtype(): void
    {
        $this->authorizeManage();

        $category = IctIssueCategory::query()->findOrFail($this->selectedCategoryId);

        $this->validate([
            'newSubtype' => [
                'required', 'string', 'max:120',
                Rule::unique('ict_issue_subtypes', 'label')->where('ict_issue_category_id', $category->id),
            ],
        ], [
            'newSubtype.unique' => 'This category already has an option with that name.',
        ], ['newSubtype' => 'option']);

        $subtype = $category->subtypes()->create([
            'label' => trim($this->newSubtype),
            'is_active' => true,
            'sort_order' => ((int) $category->subtypes()->max('sort_order')) + 10,
        ]);

        $this->audit('create_issue_subtype', 'ict_issue_subtypes', $subtype->id, null, $subtype->toArray());

        $this->reset('newSubtype');
        $this->dispatch('toast', type: 'success', message: 'Option added.');
    }

    public function toggleSubtype(int $subtypeId): void
    {
        $this->authorizeManage();

        $subtype = IctIssueSubtype::query()->findOrFail($subtypeId);
        $old = $subtype->toArray();
        $subtype->update(['is_active' => ! $subtype->is_active]);

        $this->audit('update_issue_subtype', 'ict_issue_subtypes', $subtype->id, $old, $subtype->fresh()->toArray());
    }

    public function moveSubtype(int $subtypeId, string $direction): void
    {
        $this->authorizeManage();
        abort_unless(in_array($direction, ['up', 'down'], true), 422);

        $subtype = IctIssueSubtype::query()->findOrFail($subtypeId);

        $this->move($subtype->category->subtypes()->get(), $subtypeId, $direction, 'ict_issue_subtypes');
    }

    /**
     * Swap a row with its neighbour by renumbering the whole list 10, 20, 30...: sort_order values may tie (or all be
     * zero), so swapping two values alone would not reliably move anything.
     */
    protected function move($rows, int $id, string $direction, string $targetType): void
    {
        $ids = $rows->pluck('id')->all();
        $index = array_search($id, $ids, true);
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || ! isset($ids[$swapWith])) {
            return;
        }

        [$ids[$index], $ids[$swapWith]] = [$ids[$swapWith], $ids[$index]];

        $model = $targetType === 'ict_issue_categories' ? IctIssueCategory::class : IctIssueSubtype::class;

        foreach ($ids as $position => $rowId) {
            $model::query()->whereKey($rowId)->update(['sort_order' => ($position + 1) * 10]);
        }

        $this->audit('reorder_'.($targetType === 'ict_issue_categories' ? 'issue_categories' : 'issue_subtypes'), $targetType, $id, null, ['order' => $ids]);
    }

    protected function audit(string $action, string $targetType, ?int $targetId, ?array $old, ?array $new): void
    {
        AuditLog::record($action, Permission::MODULE_ASSETS, $targetType, $targetId, $old, $new);
    }

    protected function canManage(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('assets.manage_issue_categories'));
    }

    protected function authorizeManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    public function render()
    {
        $categories = IctIssueCategory::query()->ordered()->withCount('subtypes')->get();
        $selected = $categories->firstWhere('id', $this->selectedCategoryId);

        return view('livewire.assets.settings.issue-categories-manager', [
            'categories' => $categories,
            'selected' => $selected,
            'subtypes' => $selected ? $selected->subtypes()->get() : collect(),
            'deviceLabels' => ['asset' => 'Assets', 'phone' => 'Phones', 'network' => 'Network devices'],
        ]);
    }
}
