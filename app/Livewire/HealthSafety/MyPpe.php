<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsPpeIssue;
use App\Models\Permission;
use App\Services\HealthSafety\PpeComplianceService;
use App\Services\HealthSafety\PpeIssueService;
use Livewire\Component;

/**
 * The signed-in member of staff's own PPE: what they hold, when each is due for replacement, a button to confirm they
 * received it, and any gaps against their job title's entitlements. It shows ONLY the viewer's own issues and needs no
 * Health & Safety permission, just module access and an employee record.
 */
class MyPpe extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);

        abort_unless($this->actorEmployee(), 403, 'My PPE needs an employee record.');
    }

    public function confirm(int $issueId, PpeIssueService $issues): void
    {
        $issue = HsPpeIssue::query()->where('employee_id', $this->actorEmployee()?->id ?? 0)->find($issueId);

        // Somebody else's issue is a 403 from the service as well, but never even looked up here.
        abort_unless($issue, 403, 'This PPE was not issued to you.');

        $issues->acknowledge($this->actor(), $issue);
        $this->dispatch('toast', type: 'success', message: 'Thank you: receipt confirmed.');
    }

    public function render(PpeComplianceService $compliance)
    {
        $employee = $this->actorEmployee();
        abort_unless($employee, 403);

        $mine = HsPpeIssue::query()->with('type:id,name')->where('employee_id', $employee->id);

        return view('livewire.health_safety.my-ppe', [
            'open' => (clone $mine)->open()->orderByRaw('replace_due_on is null')->orderBy('replace_due_on')->get(),
            'closed' => (clone $mine)->where('status', '!=', HsPpeIssue::STATUS_ISSUED)->orderByDesc('closed_on')->orderByDesc('id')->limit(12)->get(),
            'gaps' => $compliance->rowsForEmployee($employee),
            'states' => PpeComplianceService::STATES,
        ]);
    }
}
