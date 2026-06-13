<?php

namespace App\Livewire\Transport;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Vehicle;
use App\Models\VehicleIssue;
use App\Repositories\Transport\VehicleRepository;
use App\Services\Transport\TransportService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Issues extends Component
{
    use EnforcesModuleAccess;
    use WithFileUploads;
    use WithPagination;

    public ?int $vehicleId = null;

    public ?TemporaryUploadedFile $photo = null;

    public string $status = '';

    public string $severity = '';

    public array $form = [
        'issue_types' => [],
        'severity' => 'low',
        'description' => '',
    ];

    public function mount(VehicleRepository $vehicles): void
    {
        $this->enforceLivewireModule('transport');
        $this->vehicleId = auth()->user() ? $vehicles->assignedTo(auth()->user())?->id : null;
    }

    public function submit(TransportService $transport): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin', 'transport_manager') && ! $user->hasPermission('transport.report_issues'))) {
            abort(403);
        }

        $vehicle = $this->vehicleId ? Vehicle::query()->find($this->vehicleId) : null;

        if (! $vehicle) {
            throw ValidationException::withMessages(['vehicleId' => 'Select an assigned vehicle first.']);
        }

        $validated = $this->validate([
            'photo' => ['nullable', 'image', 'max:4096'],
            'form.issue_types' => ['required', 'array', 'min:1'],
            'form.issue_types.*' => ['required', Rule::in(VehicleIssue::ISSUE_TYPES)],
            'form.severity' => ['required', Rule::in(VehicleIssue::SEVERITIES)],
            'form.description' => ['required', 'string', 'max:5000'],
        ]);

        $transport->reportIssue($vehicle, $user, $validated['form'], $this->photo);

        $this->form = [
            'issue_types' => [],
            'severity' => 'low',
            'description' => '',
        ];
        $this->photo = null;

        $this->dispatch('toast', type: 'success', message: 'Issue reported.');
    }

    public function updateStatus(int $issueId, string $status, TransportService $transport): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin', 'transport_manager') && ! $user->hasPermission('transport.manage_issues'))) {
            abort(403);
        }

        abort_unless(in_array($status, VehicleIssue::STATUSES, true), 422);

        $transport->updateIssueStatus(VehicleIssue::query()->findOrFail($issueId), $status);
        $this->dispatch('toast', type: 'success', message: 'Issue status updated.');
    }

    public function render(VehicleRepository $vehicles)
    {
        $user = auth()->user();
        $canManage = $user?->hasRoles('super_admin', 'transport_manager') || $user?->hasPermission('transport.manage_issues');
        $assignedVehicle = $user ? $vehicles->assignedTo($user) : null;

        $availableVehicles = Vehicle::query()
            ->when(! $canManage, function ($query) use ($user): void {
                $query->where(function ($inner) use ($user): void {
                    $inner->where('assigned_user_id', $user?->id)
                        ->orWhere('assigned_driver_id', $user?->id);
                });
            })
            ->where('status', '!=', Vehicle::STATUS_RETIRED)
            ->orderBy('number_plate')
            ->get();

        $issues = VehicleIssue::query()
            ->with(['vehicle', 'reporter'])
            ->when(! $canManage, function ($query) use ($user): void {
                $query->where('reported_by', $user?->id)
                    ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->where('assigned_user_id', $user?->id));
            })
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->when($this->severity, fn ($query) => $query->where('severity', $this->severity))
            ->latest('reported_at')
            ->paginate(12);

        return view('livewire.transport.issues', [
            'canManage' => $canManage,
            'assignedVehicle' => $assignedVehicle,
            'availableVehicles' => $availableVehicles,
            'issues' => $issues,
            'issueTypes' => VehicleIssue::ISSUE_TYPES,
            'severities' => VehicleIssue::SEVERITIES,
            'statuses' => VehicleIssue::STATUSES,
        ]);
    }
}
