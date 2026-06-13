<?php

namespace App\Services\Transport;

use App\Events\Transport\MaintenanceDue;
use App\Events\Transport\VehicleIssueReported;
use App\Models\MaintenanceRecord;
use App\Models\MileageLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignmentHistory;
use App\Models\VehicleExpense;
use App\Models\VehicleIssue;
use App\Repositories\Transport\VehicleRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransportService
{
    public function __construct(
        protected VehicleRepository $vehicles
    ) {}

    public function saveVehicle(array $payload, ?Vehicle $vehicle = null, ?UploadedFile $photo = null): Vehicle
    {
        $payload = $this->normalizeNullableIds($payload, [
            'assigned_user_id',
            'assigned_driver_id',
            'department_id',
            'year_purchased',
        ]);

        $payload['is_pool_car'] = (bool) ($payload['is_pool_car'] ?? false);

        if ($payload['is_pool_car']) {
            $payload['assigned_user_id'] = null;
        }

        if (($payload['driver_type'] ?? null) !== Vehicle::DRIVER_ASSIGNED) {
            $payload['assigned_driver_id'] = null;
        }

        if (! $payload['is_pool_car'] && empty($payload['assigned_user_id'])) {
            throw ValidationException::withMessages([
                'form.assigned_user_id' => 'Assigned employee is required for non-pool cars.',
            ]);
        }

        if (($payload['driver_type'] ?? null) === Vehicle::DRIVER_ASSIGNED && empty($payload['assigned_driver_id'])) {
            throw ValidationException::withMessages([
                'form.assigned_driver_id' => 'Assigned driver is required when driver type is assigned driver.',
            ]);
        }

        if (! empty($payload['assigned_user_id']) && $this->vehicles->activeAssignmentsForUser((int) $payload['assigned_user_id'], $vehicle?->id)) {
            throw ValidationException::withMessages([
                'form.assigned_user_id' => 'This employee already has an active vehicle assignment.',
            ]);
        }

        if ($photo) {
            $payload['photo_path'] = $photo->store('transport/vehicles', 'public');
        }

        return DB::transaction(function () use ($payload, $vehicle): Vehicle {
            if ($vehicle) {
                $oldUserId = $vehicle->assigned_user_id;
                $oldDriverId = $vehicle->assigned_driver_id;
                $vehicle->update($payload);

                if ($oldUserId !== $vehicle->assigned_user_id || $oldDriverId !== $vehicle->assigned_driver_id) {
                    $this->recordAssignmentChange($vehicle, auth()->id(), 'Vehicle assignment changed.');
                }

                return $vehicle->refresh();
            }

            $created = Vehicle::query()->create($payload);
            $this->recordAssignmentChange($created, auth()->id(), 'Initial assignment.');

            return $created;
        });
    }

    public function assignVehicle(Vehicle $vehicle, ?int $userId, ?int $driverId, ?int $assignedBy, ?string $notes = null): Vehicle
    {
        if ($userId && $this->vehicles->activeAssignmentsForUser($userId, $vehicle->id)) {
            throw ValidationException::withMessages([
                'assignmentUserId' => 'This employee already has an active vehicle assignment.',
            ]);
        }

        return DB::transaction(function () use ($vehicle, $userId, $driverId, $assignedBy, $notes): Vehicle {
            VehicleAssignmentHistory::query()
                ->where('vehicle_id', $vehicle->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now()]);

            $vehicle->update([
                'assigned_user_id' => $userId,
                'assigned_driver_id' => $driverId,
                'is_pool_car' => $userId ? false : $vehicle->is_pool_car,
                'driver_type' => $driverId ? Vehicle::DRIVER_ASSIGNED : Vehicle::DRIVER_SELF_DRIVE,
            ]);

            $this->recordAssignmentChange($vehicle->refresh(), $assignedBy, $notes ?: 'Vehicle assignment updated.');

            return $vehicle;
        });
    }

    public function unassignVehicle(Vehicle $vehicle, ?int $assignedBy, ?string $notes = null): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $assignedBy, $notes): Vehicle {
            VehicleAssignmentHistory::query()
                ->where('vehicle_id', $vehicle->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now()]);

            $vehicle->update([
                'assigned_user_id' => null,
                'assigned_driver_id' => null,
                'is_pool_car' => true,
                'driver_type' => Vehicle::DRIVER_SELF_DRIVE,
            ]);

            VehicleAssignmentHistory::query()->create([
                'vehicle_id' => $vehicle->id,
                'assigned_by' => $assignedBy,
                'assigned_at' => now(),
                'unassigned_at' => now(),
                'notes' => $notes ?: 'Vehicle unassigned.',
            ]);

            return $vehicle->refresh();
        });
    }

    public function logMileage(Vehicle $vehicle, User $driver, array $payload): MileageLog
    {
        $mileageAfter = (int) ($payload['mileage_after'] ?? 0);
        $mileageBefore = (int) ($payload['mileage_before'] ?? $vehicle->current_mileage);

        if ($mileageAfter <= $mileageBefore) {
            throw ValidationException::withMessages([
                'mileage_after' => 'New mileage must be greater than previous mileage.',
            ]);
        }

        $log = MileageLog::query()->create([
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'mileage_before' => $mileageBefore,
            'mileage_after' => $mileageAfter,
            'trip_date' => $payload['trip_date'] ?? today()->toDateString(),
            'trip_purpose' => $payload['trip_purpose'] ?? 'Official trip',
            'recorded_at' => now(),
        ]);

        $vehicle->refresh();

        if ($vehicle->maintenance_remaining_km <= 500) {
            event(new MaintenanceDue($vehicle));
        }

        return $log;
    }

    public function reportIssue(Vehicle $vehicle, User $reporter, array $payload, ?UploadedFile $photo = null): VehicleIssue
    {
        $data = [
            'vehicle_id' => $vehicle->id,
            'reported_by' => $reporter->id,
            'issue_types' => array_values($payload['issue_types'] ?? []),
            'description' => $payload['description'] ?? '',
            'severity' => $payload['severity'] ?? 'low',
            'status' => VehicleIssue::STATUS_OPEN,
            'reported_at' => now(),
        ];

        if ($photo) {
            $data['photo_path'] = $photo->store('transport/issues', 'public');
        }

        $issue = VehicleIssue::query()->create($data);

        event(new VehicleIssueReported($issue));

        return $issue;
    }

    public function updateIssueStatus(VehicleIssue $issue, string $status): VehicleIssue
    {
        $issue->update([
            'status' => $status,
            'resolved_at' => $status === VehicleIssue::STATUS_RESOLVED ? now() : null,
        ]);

        return $issue->refresh();
    }

    public function recordMaintenance(Vehicle $vehicle, array $payload, ?UploadedFile $receipt = null): MaintenanceRecord
    {
        $payload = $this->normalizeNullableIds($payload, ['next_service_mileage', 'mileage_at_service']);
        $payload['vehicle_id'] = $vehicle->id;

        if ($receipt) {
            $payload['receipt_photo_path'] = $receipt->store('transport/maintenance', 'public');
        }

        $record = MaintenanceRecord::query()->create($payload);

        if (($payload['maintenance_type'] ?? null) === 'repair' || ($vehicle->status === Vehicle::STATUS_MAINTENANCE)) {
            $vehicle->update(['status' => Vehicle::STATUS_ACTIVE]);
        }

        return $record;
    }

    public function recordExpense(Vehicle $vehicle, array $payload, ?UploadedFile $receipt = null, ?int $recordedBy = null): VehicleExpense
    {
        $payload['vehicle_id'] = $vehicle->id;
        $payload['recorded_by'] = $recordedBy;
        $payload['currency'] = strtoupper($payload['currency'] ?? 'GHS');

        if ($receipt) {
            $payload['receipt_photo_path'] = $receipt->store('transport/expenses', 'public');
        }

        return VehicleExpense::query()->create($payload);
    }

    public function renewDocument(Vehicle $vehicle, string $type, string $expiryDate, ?float $amount = null, ?int $recordedBy = null): Vehicle
    {
        $column = $type === 'road_worthiness' ? 'road_worthiness_expiry_date' : 'insurance_expiry_date';
        $vehicle->update([$column => $expiryDate]);

        if ($amount !== null && $amount > 0) {
            $this->recordExpense($vehicle, [
                'expense_type' => $type,
                'amount' => $amount,
                'currency' => 'GHS',
                'description' => str($type)->replace('_', ' ')->title().' renewal',
                'expense_date' => today()->toDateString(),
            ], null, $recordedBy);
        }

        return $vehicle->refresh();
    }

    protected function recordAssignmentChange(Vehicle $vehicle, ?int $assignedBy, ?string $notes = null): void
    {
        VehicleAssignmentHistory::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNull('unassigned_at')
            ->update(['unassigned_at' => now()]);

        if (! $vehicle->assigned_user_id && ! $vehicle->assigned_driver_id) {
            return;
        }

        VehicleAssignmentHistory::query()->create([
            'vehicle_id' => $vehicle->id,
            'user_id' => $vehicle->assigned_user_id,
            'driver_id' => $vehicle->assigned_driver_id,
            'assigned_by' => $assignedBy,
            'assigned_at' => now(),
            'notes' => $notes,
        ]);
    }

    protected function normalizeNullableIds(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] === '') {
                $payload[$key] = null;
            }
        }

        return $payload;
    }
}
