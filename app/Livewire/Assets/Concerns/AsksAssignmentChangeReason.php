<?php

namespace App\Livewire\Assets\Concerns;

use App\Services\Assets\AssetAssignmentService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

/**
 * The "Reassigned (handover)" / "Correction (picked the wrong person)" question on the Assets and Phones edit forms.
 * Asked only when an EXISTING device's holder is swapped for another person: a new device, an empty one getting its
 * first holder, and a cleared holder (general use included) need no answer. The answer is posted separately from
 * `form` and handed to AssetRecordService::save(); AssetAssignmentService re-checks it on the locked asset, so a
 * tampered or missing answer is refused there too. Needs $form, $editingAssetId and ScopesAssetsByActor.
 */
trait AsksAssignmentChangeReason
{
    /** 'reassigned' | 'correction' | '' (nothing chosen yet; there is deliberately no default). */
    public string $assignmentChange = '';

    /** The holder the form was opened with. Locked: the browser cannot claim the holder did not change. */
    #[Locked]
    public ?int $originalAssigneeId = null;

    protected function rememberOriginalAssignee(?int $employeeId): void
    {
        $this->originalAssigneeId = $employeeId;
        $this->assignmentChange = '';
        $this->resetErrorBag('assignmentChange');
    }

    protected function forgetOriginalAssignee(): void
    {
        $this->rememberOriginalAssignee(null);
    }

    /** True when an existing device's holder has been changed to a DIFFERENT person (not cleared, not newly set). */
    protected function assigneeChanged(): bool
    {
        $new = $this->form['assigned_to_employee_id'] ?? null;

        if (! $this->editingAssetId || ! $this->originalAssigneeId || blank($new) || ($this->form['is_general_use'] ?? false)) {
            return false;
        }

        return (int) $new !== $this->originalAssigneeId;
    }

    /** @return array<string, array<int, mixed>> */
    protected function assignmentChangeRules(): array
    {
        return [
            'assignmentChange' => [
                Rule::requiredIf(fn () => $this->assigneeChanged()),
                'nullable',
                Rule::in(AssetAssignmentService::KINDS),
            ],
        ];
    }

    /** @return array<string, string> */
    protected function assignmentChangeMessages(): array
    {
        return [
            'assignmentChange.required' => 'Say whether this is a handover to a new person or a correction of a mistake.',
            'assignmentChange.in' => 'Choose either a handover or a correction.',
        ];
    }

    /** What save() passes on: the answer, or null when no question applied. */
    protected function assignmentChangeAnswer(): ?string
    {
        return $this->assigneeChanged() ? $this->assignmentChange : null;
    }

    /** @return array<string, mixed> */
    protected function assignmentChangeViewData(): array
    {
        return ['askAssignmentChange' => $this->assigneeChanged()];
    }
}
