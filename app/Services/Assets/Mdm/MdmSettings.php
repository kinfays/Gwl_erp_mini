<?php

namespace App\Services\Assets\Mdm;

use App\Services\Assets\Mdm\Exceptions\MdmNotConfiguredException;

/** Typed, validated access to the gwl.mdm_* config, so services fail with a message that names the missing setting. */
class MdmSettings
{
    public const MODE_PULL = 'pull';

    public const MODE_PUSH = 'push';

    public function enabled(): bool
    {
        return (bool) config('gwl.mdm_enabled');
    }

    public function projectId(): string
    {
        return $this->required('gwl.mdm_google_project_id', 'GOOGLE_CLOUD_PROJECT_ID');
    }

    /** enterprises/LC0xxxx — only known after `mdm:enterprise-create`. */
    public function enterpriseName(): string
    {
        return $this->required('gwl.mdm_enterprise_name', 'ANDROID_MANAGEMENT_ENTERPRISE_ID');
    }

    public function pubsubTopic(): string
    {
        return $this->required('gwl.mdm_pubsub_topic', 'GWL_MDM_PUBSUB_TOPIC');
    }

    public function pubsubSubscription(): string
    {
        return $this->required('gwl.mdm_pubsub_subscription', 'GWL_MDM_PUBSUB_SUBSCRIPTION');
    }

    /** "push" or "pull"; anything else (including a typo) falls back to pull, the safe local default. */
    public function pubsubMode(): string
    {
        return strtolower((string) config('gwl.mdm_pubsub_mode')) === self::MODE_PUSH ? self::MODE_PUSH : self::MODE_PULL;
    }

    public function isPushMode(): bool
    {
        return $this->pubsubMode() === self::MODE_PUSH;
    }

    public function enrollmentTokenMinutes(): int
    {
        return max(1, (int) config('gwl.mdm_enrollment_token_minutes', 60));
    }

    /** @return array{message: ?string, phone: ?string, address: ?string} */
    public function lostModeDefaults(): array
    {
        return [
            'message' => $this->nullable(config('gwl.mdm_lost_mode_message')),
            'phone' => $this->nullable(config('gwl.mdm_lost_mode_phone')),
            'address' => $this->nullable(config('gwl.mdm_lost_mode_address')),
        ];
    }

    private function required(string $key, string $envName): string
    {
        $value = config($key);

        if (! is_string($value) || trim($value) === '') {
            throw new MdmNotConfiguredException("{$envName} is not set.");
        }

        return trim($value);
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
