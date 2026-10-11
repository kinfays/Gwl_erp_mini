<?php

namespace App\Livewire\Transport;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleIssue;
use App\Repositories\Transport\VehicleRepository;
use App\Services\Transport\TransportScope;
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
        $user = $this->actor();

        if (! $this->scope()->can($user, 'transport.report_issues')) {
            abort(403);
        }

        if (! $this->vehicleId) {
            throw ValidationException::withMessages(['vehicleId' => 'Select an assigned vehicle first.']);
        }

        // Only a vehicle this user may report on (inside their reach, and their own unless they manage issues);
        // anything else, including a tampered id, answers 404.
        $vehicle = $this->availableVehicles($user)->whereKey($this->vehicleId)->firstOrFail();

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
        $user = $this->actor();

        if (! $this->scope()->can($user, 'transport.manage_issues')) {
            abort(403);
        }

        abort_unless(in_array($status, VehicleIssue::STATUSES, true), 422);

        $transport->updateIssueStatus(VehicleIssue::query()->visibleTo($user)->findOrFail($issueId), $status, $user);
        $this->dispatch('toast', type: 'success', message: 'Issue status updated.');
    }

    protected function scope(): TransportScope
    {
        return app(TransportScope::class);
    }

    protected function actor(): User
    {
        $user = auth()->user();
        abort_unless($user, 403);

        return $user;
    }

    /** Vehicles the user may report an issue on: in their reach, not retired, and their own unless they manage issues. */
    protected function availableVehicles(User $user)
    {
        return $this->scope()->vehicles($user)
            ->where('vehicles.status', '!=', Vehicle::STATUS_RETIRED)
            ->when(! $this->scope()->can($user, 'transport.manage_issues'), function ($query) use ($user): void {
                $query->where(function ($inner) use ($user): void {
                    $inner->where('vehicles.assigned_user_id', $user->id)
                        ->orWhere('vehicles.assigned_driver_id', $user->id);
                });
            });
    }

    public function render(VehicleRepository $vehicles)
    {
        $user = $this->actor();
        $canManage = $this->scope()->can($user, 'transport.manage_issues');
        $assignedVehicle = $vehicles->assignedTo($user);

        $availableVehicles = $this->availableVehicles($user)->orderBy('number_plate')->get();

        $issues = VehicleIssue::query()
            ->visibleTo($user)
            ->with(['vehicle', 'reporter'])
            ->when(! $canManage, function ($query) use ($user): void {
                // Grouped, so the OR cannot escape the location scope above it.
                $query->where(function ($inner) use ($user): void {
                    $inner->where('reported_by', $user->id)
                        ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->where('assigned_user_id', $user->id));
                });
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
