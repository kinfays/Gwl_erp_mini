{{--
    Shown on an EDIT only, once "Assigned To" has been changed from one person to another. The user must say which kind
    of change it is (no default): a handover makes the old person the "previous assigned to"; a correction does not.
    Bound to the Livewire property `assignmentChange` (see Concerns/AsksAssignmentChangeReason).
--}}
@props(['show' => false])

@if ($show || (isset($errors) && $errors->has('assignmentChange')))
    <fieldset class="span-2 ui-field" id="f-assignment-change">
        <legend class="ui-label">What kind of change is this?<span class="ui-req" aria-hidden="true">*</span></legend>

        <label class="ui-check" for="f-assignment-change-reassigned">
            <input type="radio" id="f-assignment-change-reassigned" name="assignmentChange" value="reassigned" wire:model.live="assignmentChange">
            <span class="ui-check-text">
                <span class="ui-check-label">Reassigned to a new person</span>
                <span class="ui-check-desc">A real handover. The old holder is kept as "Previous assigned to".</span>
            </span>
        </label>

        <label class="ui-check" for="f-assignment-change-correction">
            <input type="radio" id="f-assignment-change-correction" name="assignmentChange" value="correction" wire:model.live="assignmentChange">
            <span class="ui-check-text">
                <span class="ui-check-label">Correction: the previous selection was a mistake</span>
                <span class="ui-check-desc">The wrong person is not kept as "Previous assigned to"; the history shows it as a correction.</span>
            </span>
        </label>

        @error('assignmentChange')
            <p class="ui-error" id="f-assignment-change-error">
                <x-ui.icon name="circle-alert" class="icon-sm" />
                <span>{{ $message }}</span>
            </p>
        @enderror
    </fieldset>
@endif
