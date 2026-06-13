<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transport\StoreMileageLogRequest;
use App\Http\Requests\Transport\StoreVehicleIssueRequest;
use App\Models\User;
use App\Repositories\Transport\VehicleRepository;
use App\Services\Transport\TransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransportApiController extends Controller
{
    public function myVehicle(Request $request, VehicleRepository $vehicles): JsonResponse
    {
        $user = $this->tokenUser($request);
        $vehicle = $vehicles->assignedTo($user);

        return response()->json([
            'vehicle' => $vehicle ? [
                'uuid' => $vehicle->uuid,
                'type' => $vehicle->type,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'color' => $vehicle->color,
                'number_plate' => $vehicle->number_plate,
                'current_mileage' => $vehicle->current_mileage,
                'next_maintenance_mileage' => $vehicle->next_maintenance_mileage,
                'maintenance_remaining_km' => $vehicle->maintenance_remaining_km,
                'insurance_expiry_date' => $vehicle->insurance_expiry_date?->toDateString(),
                'road_worthiness_expiry_date' => $vehicle->road_worthiness_expiry_date?->toDateString(),
                'status' => $vehicle->status,
                'assigned_user' => $vehicle->assignedUser?->full_name ?? $vehicle->assignedUser?->email,
                'assigned_driver' => $vehicle->assignedDriver?->full_name ?? $vehicle->assignedDriver?->email,
            ] : null,
        ]);
    }

    public function storeMileage(StoreMileageLogRequest $request, VehicleRepository $vehicles, TransportService $transport): JsonResponse
    {
        $user = $this->tokenUser($request);
        $this->ensureCan($user, 'transport.log_mileage');

        $vehicle = $vehicles->assignedTo($user);

        if (! $vehicle) {
            throw ValidationException::withMessages([
                'vehicle' => 'No active assigned vehicle found for this account.',
            ]);
        }

        $payload = $request->validated();
        $payload['mileage_before'] = $payload['mileage_before'] ?? $vehicle->current_mileage;

        $log = $transport->logMileage($vehicle, $user, $payload);

        return response()->json([
            'status' => 'ok',
            'mileage_log_uuid' => $log->uuid,
            'current_mileage' => $log->vehicle->fresh()->current_mileage,
        ], 201);
    }

    public function storeIssue(StoreVehicleIssueRequest $request, VehicleRepository $vehicles, TransportService $transport): JsonResponse
    {
        $user = $this->tokenUser($request);
        $this->ensureCan($user, 'transport.report_issues');

        $vehicle = $vehicles->assignedTo($user);

        if (! $vehicle) {
            throw ValidationException::withMessages([
                'vehicle' => 'No active assigned vehicle found for this account.',
            ]);
        }

        $issue = $transport->reportIssue($vehicle, $user, $request->validated(), $request->file('photo'));

        return response()->json([
            'status' => 'ok',
            'issue_uuid' => $issue->uuid,
            'issue_status' => $issue->status,
        ], 201);
    }

    protected function tokenUser(Request $request): User
    {
        $token = trim((string) $request->bearerToken());

        if ($token === '') {
            abort(401, 'Unauthorized.');
        }

        $user = User::query()->where('api_token', $token)->first();

        if (! $user || ! $user->is_active) {
            abort(401, 'Unauthorized.');
        }

        return $user;
    }

    protected function ensureCan(User $user, string $permission): void
    {
        if ($user->hasRoles('super_admin', 'transport_manager') || $user->hasPermission($permission)) {
            return;
        }

        abort(403, 'Forbidden.');
    }
}
