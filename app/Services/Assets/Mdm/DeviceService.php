<?php

namespace App\Services\Assets\Mdm;

use App\Models\IctAsset;
use App\Models\MdmDevice;
use App\Models\MdmEnrollmentToken;
use App\Models\MdmPolicy;
use App\Models\Permission;
use App\Models\User;
use App\Services\Assets\Mdm\Exceptions\MdmException;
use App\Services\Assets\Mdm\Exceptions\MdmGatewayException;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps mdm_devices in step with what Google reports, and ties each device that enrolls back to its phone record.
 *
 * Devices are matched on Google's device name. The first ENROLLMENT notification is matched to an asset through
 * enrollmentTokenData.asset_id (put there by EnrollmentService) and then cross-checked against the asset's serial
 * number / IMEI. If they disagree — or there is nothing to compare — the device is still recorded but flagged for
 * review rather than silently trusted.
 */
class DeviceService
{
    /** Fields kept from each applicationReports[] entry (a full report is far larger than the screen needs). */
    private const APP_REPORT_FIELDS = ['packageName', 'displayName', 'versionName', 'versionCode', 'state', 'applicationSource', 'installerPackageName'];

    public function __construct(
        private readonly AndroidManagementGateway $gateway,
        private readonly MdmSettings $settings,
        private readonly MdmAccessGuard $guard,
    ) {}

    /**
     * Store a Device resource from a notification or a resync: refresh a known device, or treat an unknown one as an
     * enrollment (so a STATUS_REPORT that arrives before, or instead of, its ENROLLMENT event is not lost).
     */
    public function ingest(array $resource): MdmDevice
    {
        $name = $resource['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new MdmException('Device notification has no device name.');
        }

        $device = MdmDevice::query()->where('google_device_name', $name)->first();

        return $device ? $this->applyReport($device, $resource) : $this->handleEnrollment($resource);
    }

    public function handleEnrollment(array $resource): MdmDevice
    {
        $name = $resource['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new MdmException('Enrollment notification has no device name.');
        }

        $existing = MdmDevice::query()->where('google_device_name', $name)->first();

        if ($existing) {
            return $this->applyReport($existing, $resource);
        }

        $resource = $this->withIdentity($resource);
        $reasons = [];

        $assetId = $this->assetIdFromTokenData($resource['enrollmentTokenData'] ?? null);
        $asset = null;

        if ($assetId === null) {
            $reasons[] = 'This phone did not enroll with an enrollment token issued by the ERP (no asset id in the token data).';
        } else {
            $asset = IctAsset::query()->where('device_category', IctAsset::DEVICE_CATEGORY_PHONE)->find($assetId);

            if (! $asset) {
                $reasons[] = "The enrollment token pointed at asset #{$assetId}, which is not a phone in the inventory.";
            }
        }

        // An asset keeps its row across a decommission (state DELETED) so re-enrolling the same phone reuses it.
        $reusable = null;

        if ($asset) {
            $current = MdmDevice::query()->where('ict_asset_id', $asset->id)->first();

            if ($current && ! $current->isDeleted()) {
                $reasons[] = "Asset “{$asset->asset_name}” is already linked to another enrolled device ({$current->google_device_name}).";
                $asset = null;
            } else {
                $reusable = $current;
            }
        }

        if ($asset) {
            array_push($reasons, ...$this->identityProblems($asset, $this->hardware($resource)));
        }

        $device = DB::transaction(function () use ($resource, $name, $asset, $reusable, $reasons) {
            $device = $reusable ?? new MdmDevice;

            $device->fill([
                'ict_asset_id' => $asset?->id,
                'google_device_name' => $name,
                'enrollment_token_name' => $resource['enrollmentTokenName'] ?? null,
                'is_lost' => false,
                'lost_at' => null,
                'needs_review' => $reasons !== [],
                'review_reason' => $reasons !== [] ? implode(' ', $reasons) : null,
            ]);

            $device = $this->applyReport($device, $resource);

            $this->markTokenUsed($resource, $asset);

            return $device;
        });

        Audit::log(
            $reasons === [] ? 'mdm_device_enrolled' : 'mdm_device_enrolled_for_review',
            Permission::MODULE_ASSETS,
            'mdm_devices',
            $device->id,
            ['asset_id' => $asset?->id, 'google_device_name' => $name, 'needs_review' => $reasons !== [], 'reasons' => $reasons]
        );

        return $device;
    }

    /** Apply a Device resource to an existing row (mapping documented per field below). */
    public function applyReport(MdmDevice $device, array $resource): MdmDevice
    {
        $compliant = array_key_exists('policyCompliant', $resource) ? (bool) $resource['policyCompliant'] : $device->policy_compliant;

        $policyName = $resource['appliedPolicyName'] ?? $resource['policyName'] ?? null;
        $policyId = $policyName ? MdmPolicy::query()->where('google_policy_name', $policyName)->value('id') : null;

        $state = $resource['state'] ?? $device->state;

        $device->fill([
            'management_mode' => $resource['managementMode'] ?? $device->management_mode,
            'state' => $state,
            'applied_state' => $resource['appliedState'] ?? $device->applied_state,
            'policy_compliant' => $compliant,
            'non_compliance' => $compliant === true ? [] : ($resource['nonComplianceDetails'] ?? $device->non_compliance ?? []),
            'android_version' => $resource['softwareInfo']['androidVersion'] ?? $device->android_version,
            'security_patch_level' => $resource['softwareInfo']['securityPatchLevel'] ?? $device->security_patch_level,
            'hardware_info' => array_replace($device->hardware_info ?? [], $this->hardware($resource)),
            'last_status_report_at' => $this->time($resource['lastStatusReportTime'] ?? null) ?? $device->last_status_report_at,
            'last_policy_sync_at' => $this->time($resource['lastPolicySyncTime'] ?? null) ?? $device->last_policy_sync_at,
            'enrolled_at' => $device->enrolled_at ?? $this->time($resource['enrollmentTime'] ?? null),
            'last_synced_at' => now(),
        ]);

        if ($policyId) {
            $device->mdm_policy_id = $policyId;
        }

        if (isset($resource['applicationReports']) && is_array($resource['applicationReports'])) {
            $device->application_reports = collect($resource['applicationReports'])
                ->map(fn ($app) => array_intersect_key((array) $app, array_flip(self::APP_REPORT_FIELDS)))
                ->sortBy(fn ($app) => strtolower($app['displayName'] ?? $app['packageName'] ?? ''))
                ->values()
                ->all();
        }

        // Google is the source of truth for lost state when it says so; leaving lost mode is only ever recorded by an
        // acknowledged STOP_LOST_MODE (CommandService), never guessed from a routine status report.
        if ($state === 'LOST' && ! $device->is_lost) {
            $device->is_lost = true;
            $device->lost_at = now();
        }

        $device->save();

        return $device;
    }

    /** devices.get, then apply. A 404 means Google no longer knows the device (deleted). */
    public function syncFromGoogle(MdmDevice $device): MdmDevice
    {
        try {
            $resource = $this->gateway->getDevice($device->google_device_name);
        } catch (MdmGatewayException $e) {
            if ($e->isNotFound()) {
                return $this->markDeleted($device);
            }

            throw $e;
        }

        return $this->applyReport($device, $resource);
    }

    /**
     * The nightly safety net: list every device Google has and reconcile. Catches anything a notification missed,
     * including devices that enrolled while events were not flowing.
     *
     * @return array{seen: int, created: int, marked_deleted: int}
     */
    public function syncAll(): array
    {
        $resources = $this->gateway->listDevices($this->settings->enterpriseName());
        $created = 0;
        $seen = [];

        foreach ($resources as $resource) {
            $known = isset($resource['name']) && MdmDevice::query()->where('google_device_name', $resource['name'])->exists();
            $this->ingest($resource);
            $seen[] = $resource['name'];
            $created += $known ? 0 : 1;
        }

        $markedDeleted = 0;

        // An empty list against a non-empty local table is far more likely a wrong enterprise or a Google hiccup
        // than a fleet that vanished, so it never mass-deletes.
        if ($seen !== []) {
            MdmDevice::query()->notDeleted()->whereNotIn('google_device_name', $seen)->get()
                ->each(function (MdmDevice $device) use (&$markedDeleted) {
                    $this->markDeleted($device);
                    $markedDeleted++;
                });
        } elseif (MdmDevice::query()->notDeleted()->exists()) {
            Log::warning('MDM device resync returned no devices; skipping the deleted-device check.');
        }

        return ['seen' => count($seen), 'created' => $created, 'marked_deleted' => $markedDeleted];
    }

    public function markDeleted(MdmDevice $device): MdmDevice
    {
        $device->forceFill(['state' => 'DELETED', 'applied_state' => 'DELETED', 'is_lost' => false, 'last_synced_at' => now()])->save();

        return $device;
    }

    /** An admin confirms the enrolled phone really is the asset it is linked to. */
    public function confirmIdentity(MdmDevice $device, User $actor): MdmDevice
    {
        if (! $this->guard->canReview($actor)) {
            throw new AuthorizationException('Only an administrator can confirm a device identity.');
        }

        $reason = $device->review_reason;

        $device->forceFill(['needs_review' => false, 'review_reason' => null])->save();

        Audit::log('mdm_device_review_cleared', Permission::MODULE_ASSETS, 'mdm_devices', $device->id, [
            'actor_id' => $actor->id,
            'asset_id' => $device->ict_asset_id,
            'reason_was' => $reason,
        ]);

        return $device;
    }

    /** @return array{asset_id: ?int} */
    private function tokenData(?string $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return ['asset_id' => is_array($decoded) && isset($decoded['asset_id']) && is_numeric($decoded['asset_id']) ? (int) $decoded['asset_id'] : null];
    }

    private function assetIdFromTokenData(?string $raw): ?int
    {
        return $this->tokenData($raw)['asset_id'];
    }

    /**
     * hardwareInfo plus the IMEI from networkInfo, in one flat array (serialNumber, manufacturer, model, imei …).
     *
     * @return array<string, mixed>
     */
    private function hardware(array $resource): array
    {
        $hardware = (array) ($resource['hardwareInfo'] ?? []);

        foreach (['imei', 'meid'] as $key) {
            if (! empty($resource['networkInfo'][$key])) {
                $hardware[$key] = $resource['networkInfo'][$key];
            }
        }

        return $hardware;
    }

    /** An ENROLLMENT notification can arrive before the first hardware report; fetch the full device once if so. */
    private function withIdentity(array $resource): array
    {
        $hardware = $this->hardware($resource);

        if (! empty($hardware['serialNumber']) || ! empty($hardware['imei'])) {
            return $resource;
        }

        try {
            return array_replace($resource, $this->gateway->getDevice($resource['name']));
        } catch (MdmGatewayException $e) {
            Log::info('MDM could not fetch device identity at enrollment; it will be flagged for review.', ['status' => $e->httpStatus]);

            return $resource;
        }
    }

    /**
     * Compare what the phone says it is with the asset record.
     *
     * @return list<string> empty when at least one identifier matched and none disagreed
     */
    private function identityProblems(IctAsset $asset, array $hardware): array
    {
        $problems = [];
        $compared = 0;

        $deviceSerial = $this->normaliseSerial($hardware['serialNumber'] ?? null);
        $assetSerial = $this->normaliseSerial($asset->serial_number);

        if ($deviceSerial !== '' && $assetSerial !== '') {
            $compared++;

            if ($deviceSerial !== $assetSerial) {
                $problems[] = "Serial number mismatch: the asset record says {$asset->serial_number}, the phone reports {$hardware['serialNumber']}.";
            }
        }

        $deviceImei = $this->normaliseImei($hardware['imei'] ?? null);
        $assetImei = $this->normaliseImei($asset->imei);

        if ($deviceImei !== '' && $assetImei !== '') {
            $compared++;

            // 14-digit and 15-digit (check digit) forms of the same IMEI must agree.
            if (substr($deviceImei, 0, 14) !== substr($assetImei, 0, 14)) {
                $problems[] = "IMEI mismatch: the asset record says {$asset->imei}, the phone reports {$hardware['imei']}.";
            }
        }

        if ($compared === 0) {
            $problems[] = 'Identity could not be verified: the phone reported no serial number or IMEI that can be compared with the asset record.';
        }

        return $problems;
    }

    private function normaliseSerial(mixed $value): string
    {
        return strtoupper(trim((string) $value));
    }

    private function normaliseImei(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private function markTokenUsed(array $resource, ?IctAsset $asset): void
    {
        $tokenName = $resource['enrollmentTokenName'] ?? null;

        $query = MdmEnrollmentToken::query()->whereNull('used_at');

        if (is_string($tokenName) && $tokenName !== '') {
            $query->where('google_token_name', $tokenName);
        } elseif ($asset) {
            $query->where('ict_asset_id', $asset->id);
        } else {
            return;
        }

        $query->update(['used_at' => now()]);
    }

    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
