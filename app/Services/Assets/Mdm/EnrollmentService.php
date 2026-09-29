<?php

namespace App\Services\Assets\Mdm;

use App\Models\MdmEnrollmentToken;
use App\Models\MdmPolicy;
use App\Models\Permission;
use App\Models\User;
use App\Services\Assets\Mdm\Exceptions\MdmException;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Mints the one-time enrollment token (and its QR payload) for one specific phone.
 *
 * The token's additionalData carries {asset_id, requested_by}. Google hands it back on the ENROLLMENT event, which
 * is how DeviceService links the device that scanned the QR to the exact ict_assets record it was generated for.
 * Only the token's resource name is stored; the QR payload is a live credential and is shown once, never kept.
 */
class EnrollmentService
{
    public function __construct(
        private readonly AndroidManagementGateway $gateway,
        private readonly MdmSettings $settings,
        private readonly MdmAccessGuard $guard,
    ) {}

    /**
     * @return array{token: MdmEnrollmentToken, qr_payload: string, expires_at: Carbon}
     *
     * @throws AuthorizationException when the actor may not enroll phones at all
     * @throws ValidationException when the asset or policy is not eligible (including an asset outside the actor's region)
     */
    public function generate(User $actor, int $assetId, int $policyId): array
    {
        if (! $this->guard->has($actor, 'assets.mdm_enroll')) {
            throw new AuthorizationException('You do not have permission to enroll phones.');
        }

        // Re-scoped on every call: the id came from a form field the user could have edited.
        $asset = $this->guard->enrollableAssets($actor)->find($assetId);

        if (! $asset) {
            throw ValidationException::withMessages([
                'asset' => 'That phone is not available to enroll. It must be an active handset in your region with a serial number or IMEI, and not already enrolled.',
            ]);
        }

        $policy = MdmPolicy::query()->find($policyId);

        if (! $policy || ! $policy->isPublished()) {
            throw ValidationException::withMessages([
                'policy' => 'Choose a policy that has been published to Google.',
            ]);
        }

        $token = $this->gateway->createEnrollmentToken($this->settings->enterpriseName(), [
            'policyName' => $policy->google_policy_name,
            'duration' => ($this->settings->enrollmentTokenMinutes() * 60).'s',
            'oneTimeOnly' => true,
            // Fully managed only: no work profile, no personal side.
            'allowPersonalUsage' => 'PERSONAL_USAGE_DISALLOWED',
            'additionalData' => json_encode(['asset_id' => $asset->id, 'requested_by' => $actor->id]),
        ]);

        $name = $token['name'] ?? null;
        $qr = $token['qrCode'] ?? null;

        if (! is_string($name) || $name === '' || ! is_string($qr) || $qr === '') {
            throw new MdmException('Google did not return a usable enrollment token.');
        }

        $expiresAt = isset($token['expirationTimestamp'])
            ? Carbon::parse($token['expirationTimestamp'])
            : now()->addMinutes($this->settings->enrollmentTokenMinutes());

        $record = MdmEnrollmentToken::query()->create([
            'ict_asset_id' => $asset->id,
            'mdm_policy_id' => $policy->id,
            'google_token_name' => $name,
            'expires_at' => $expiresAt,
            'created_by' => $actor->id,
        ]);

        Audit::log('mdm_enrollment_token_created', Permission::MODULE_ASSETS, 'ict_assets', $asset->id, [
            'enrollment_token_id' => $record->id,
            'policy' => $policy->name,
            'expires_at' => $expiresAt->toIso8601String(),
            'actor_id' => $actor->id,
        ]);

        return ['token' => $record, 'qr_payload' => $qr, 'expires_at' => $expiresAt];
    }
}
