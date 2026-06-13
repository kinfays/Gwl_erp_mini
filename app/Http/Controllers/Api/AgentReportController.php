<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentReport;
use App\Models\IctAsset;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AgentReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $token = trim((string) $request->bearerToken());

        if ($token === '') {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $user = User::query()->where('api_token', $token)->first();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        if (
            ! $user->hasRoles('super_admin', 'admin', 'ict_team')
            && ! $user->hasPermission('assets.agent_ingest')
        ) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $payload = Validator::make($request->all(), [
            'hostname' => ['required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'mac_address' => ['nullable', 'string', 'max:255'],
            'os_name' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'cpu_name' => ['nullable', 'string', 'max:255'],
            'ram_gb' => ['nullable', 'numeric', 'between:0,9999.99'],
            'logged_on_user' => ['nullable', 'string', 'max:255'],
            'last_boot_time' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'bios_version' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $employee = $user->employee ?? $user->employeeByStaffId;
        $reportingRegionId = $employee?->region_id;

        $asset = $this->findMatchingAsset($payload, $user, $reportingRegionId);

        if ($asset) {
            $asset->update([
                'serial_number' => $asset->serial_number ?: ($payload['serial_number'] ?? null),
                'cpu_name' => $payload['cpu_name'] ?? $asset->cpu_name,
                'ram_gb' => $payload['ram_gb'] ?? $asset->ram_gb,
                'os_name' => $payload['os_name'] ?? $asset->os_name,
                'os_version' => $payload['os_version'] ?? $asset->os_version,
                'mac_address' => $payload['mac_address'] ?? $asset->mac_address,
                'hostname' => $payload['hostname'] ?? $asset->hostname,
                'manufacturer' => $payload['manufacturer'] ?? $asset->manufacturer,
                'model_name' => $payload['model'] ?? $asset->model_name,
                'bios_version' => $payload['bios_version'] ?? $asset->bios_version,
                'last_boot_at' => $this->parseBootTime($payload['last_boot_time'] ?? null) ?? $asset->last_boot_at,
                'last_seen_at' => now(),
                'agent_last_report_at' => now(),
            ]);
        }

        $report = AgentReport::query()->create([
            'user_id' => $user->id,
            'ict_asset_id' => $asset?->id,
            'reporting_region_id' => $reportingRegionId,
            'matched' => (bool) $asset,
            'hostname' => $payload['hostname'] ?? null,
            'serial_number' => $payload['serial_number'] ?? null,
            'mac_address' => $payload['mac_address'] ?? null,
            'os_name' => $payload['os_name'] ?? null,
            'os_version' => $payload['os_version'] ?? null,
            'cpu_name' => $payload['cpu_name'] ?? null,
            'ram_gb' => $payload['ram_gb'] ?? null,
            'logged_on_user' => $payload['logged_on_user'] ?? null,
            'last_boot_time' => $payload['last_boot_time'] ?? null,
            'manufacturer' => $payload['manufacturer'] ?? null,
            'model' => $payload['model'] ?? null,
            'bios_version' => $payload['bios_version'] ?? null,
            'payload' => $payload,
            'reported_at' => now(),
        ]);

        return response()->json([
            'status' => 'ok',
            'asset_id' => $report->ict_asset_id,
            'matched' => $report->matched,
        ]);
    }

    protected function findMatchingAsset(array $payload, User $user, ?int $reportingRegionId): ?IctAsset
    {
        $serial = trim((string) ($payload['serial_number'] ?? ''));
        $hostname = trim((string) ($payload['hostname'] ?? ''));
        $mac = $this->normalizeMac($payload['mac_address'] ?? null);

        if ($serial === '' && $hostname === '' && $mac === '') {
            return null;
        }

        $query = IctAsset::query();

        if ($this->isRegionScopedIct($user)) {
            if (! $reportingRegionId) {
                return null;
            }

            $query->where('region_id', $reportingRegionId);
        }

        $query->where(function ($where) use ($serial, $hostname, $mac) {
            if ($serial !== '') {
                $where->orWhereRaw('LOWER(serial_number) = ?', [strtolower($serial)]);
            }

            if ($hostname !== '') {
                $where->orWhereRaw('LOWER(hostname) = ?', [strtolower($hostname)]);
            }

            if ($mac !== '') {
                $where->orWhereRaw(
                    "REPLACE(REPLACE(LOWER(mac_address), ':', ''), '-', '') = ?",
                    [strtolower($mac)]
                );
            }
        });

        return $query->first();
    }

    protected function parseBootTime(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function normalizeMac(?string $value): string
    {
        return strtolower(preg_replace('/[^A-Fa-f0-9]/', '', (string) $value) ?? '');
    }

    protected function isRegionScopedIct(User $user): bool
    {
        return $user->hasRoles(User::ROLE_ICT_TEAM)
            && ! $user->hasRoles(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN);
    }
}

