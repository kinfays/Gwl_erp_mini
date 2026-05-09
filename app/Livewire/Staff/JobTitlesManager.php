<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\JobTitle;
use Livewire\Component;

class JobTitlesManager extends Component
{
    use EnforcesModuleAccess;

    public string $job_title_name = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('staff');

        if (! $this->canManageJobTitles()) {
            abort(403);
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'job_title_name' => ['required', 'string', 'max:255', 'unique:job_titles,job_title_name'],
        ]);

        $jobTitle = JobTitle::create($validated);

        AuditLog::record('create_job_title', 'staff', 'job_titles', $jobTitle->id, null, $jobTitle->toArray());

        $this->reset('job_title_name');
        session()->flash('success', 'Job title created successfully.');
        $this->dispatch('toast', type: 'success', message: 'Job title created successfully.');
    }

    public function edit(int $jobTitleId): void
    {
        $jobTitle = JobTitle::findOrFail($jobTitleId);

        $this->editingId = $jobTitle->id;
        $this->editingName = $jobTitle->job_title_name;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editingName');
    }

    public function update(): void
    {
        $jobTitle = JobTitle::findOrFail($this->editingId);

        $validated = $this->validate([
            'editingName' => ['required', 'string', 'max:255', 'unique:job_titles,job_title_name,'.$jobTitle->id],
        ]);

        $old = $jobTitle->toArray();
        $jobTitle->update([
            'job_title_name' => $validated['editingName'],
        ]);

        AuditLog::record('update_job_title', 'staff', 'job_titles', $jobTitle->id, $old, $jobTitle->fresh()->toArray());

        $this->reset('editingId', 'editingName');
        session()->flash('success', 'Job title updated successfully.');
        $this->dispatch('toast', type: 'success', message: 'Job title updated successfully.');
    }

    public function delete(int $jobTitleId): void
    {
        $jobTitle = JobTitle::withCount('employees')->findOrFail($jobTitleId);

        if ($jobTitle->employees_count > 0) {
            $this->addError('job_title_name', 'You cannot delete a job title that still has employees assigned.');
            $this->dispatch('toast', type: 'error', message: 'Job title still has employees assigned.');

            return;
        }

        $old = $jobTitle->toArray();
        $jobTitle->delete();

        AuditLog::record('delete_job_title', 'staff', 'job_titles', $jobTitleId, $old, null);
        session()->flash('success', 'Job title deleted successfully.');
        $this->dispatch('toast', type: 'success', message: 'Job title deleted successfully.');
    }

    public function render()
    {
        return view('livewire.staff.job-titles-manager', [
            'jobTitles' => JobTitle::query()
                ->withCount('employees')
                ->orderBy('job_title_name')
                ->get(),
        ]);
    }

    protected function canManageJobTitles(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('staff.manage_job_titles'));
    }
}
