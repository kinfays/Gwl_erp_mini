<?php

namespace App\Services\Assets\Mdm;

use App\Models\AuditLog;
use App\Models\MdmPolicy;
use App\Models\MdmPolicyApp;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Translates a local mdm_policies record into an Android Management API Policy and publishes it.
 *
 * Every field name here was checked against Google's discovery document (see docs/assets/mdm.md, "AMAPI notes"):
 * usbDataAccess lives under deviceConnectivityManagement, passwordRequirements is deprecated in favour of
 * passwordPolicies[], and the status-reporting flags carry an "Enabled" suffix.
 *
 * Deliberately NOT set: leaveAllSystemAppsEnabled (a provisioning extra that Google's QR payload leaves off, so
 * fully managed provisioning disables non-essential system apps) and maximumFailedPasswordsForWipe (the ERP never
 * auto-wipes).
 */
class PolicyService
{
    /**
     * Every top-level Policy field this service owns. PATCH is sent with this as its updateMask, so a field the
     * local record no longer sets (an emptied FRP list, a removed password rule) is reset on Google's side rather
     * than silently kept.
     */
    public const MANAGED_FIELDS = [
        'applications',
        'playStoreMode',
        'installAppsDisabled',
        'uninstallAppsDisabled',
        'factoryResetDisabled',
        'frpAdminEmails',
        'addUserDisabled',
        'screenCaptureDisabled',
        'cameraAccess',
        'deviceConnectivityManagement',
        'advancedSecurityOverrides',
        'passwordPolicies',
        'systemUpdate',
        'statusReportingSettings',
    ];

    public const PACKAGE_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/';

    public function __construct(
        private readonly AndroidManagementGateway $gateway,
        private readonly MdmSettings $settings,
    ) {}

    /** @return array<string, mixed> */
    public function toAmapiPayload(MdmPolicy $policy): array
    {
        $policy->loadMissing('apps');

        $applications = $policy->apps
            ->where('is_enabled', true)
            ->sortBy('package_name')
            ->map(fn (MdmPolicyApp $app) => array_filter([
                'packageName' => $app->package_name,
                'installType' => $app->install_type,
                'defaultPermissionPolicy' => $app->default_permission_policy ?: null,
            ]))
            ->values()
            ->all();

        $payload = [
            'applications' => $applications,
            'playStoreMode' => $policy->play_store_mode,
            'installAppsDisabled' => (bool) $policy->install_apps_disabled,
            'uninstallAppsDisabled' => (bool) $policy->uninstall_apps_disabled,
            'factoryResetDisabled' => (bool) $policy->factory_reset_disabled,
            'frpAdminEmails' => $this->frpEmails($policy),
            'addUserDisabled' => (bool) $policy->add_user_disabled,
            'screenCaptureDisabled' => (bool) $policy->screen_capture_disabled,
            'cameraAccess' => $policy->camera_access,
            'deviceConnectivityManagement' => ['usbDataAccess' => $policy->usb_data_access],
            'advancedSecurityOverrides' => [
                'untrustedAppsPolicy' => $policy->untrusted_apps_policy,
                'developerSettings' => $policy->developer_settings,
            ],
            'systemUpdate' => $this->systemUpdate($policy),
            'statusReportingSettings' => [
                'applicationReportsEnabled' => true,
                'deviceSettingsEnabled' => true,
                'softwareInfoEnabled' => true,
                'hardwareStatusEnabled' => true,
                // The IMEI lives under networkInfo, and the enrollment identity check needs it.
                'networkInfoEnabled' => true,
            ],
        ];

        if ($policy->password_min_length || $policy->password_quality) {
            $payload['passwordPolicies'] = [array_filter([
                'passwordScope' => 'SCOPE_DEVICE',
                'passwordQuality' => $policy->password_quality ?: null,
                'passwordMinimumLength' => $policy->password_min_length ?: null,
            ])];
        }

        return $payload;
    }

    /**
     * Blocking problems. A fully managed policy with no FORCE_INSTALLED app would leave a freshly reset phone
     * with nothing but a locked-down home screen, so it is refused outright.
     *
     * @throws ValidationException
     */
    public function validateForPublish(MdmPolicy $policy): void
    {
        $policy->loadMissing('apps');
        $errors = [];

        $enabled = $policy->apps->where('is_enabled', true);

        if ($enabled->where('install_type', MdmPolicyApp::INSTALL_TYPE_FORCE_INSTALLED)->isEmpty()) {
            $errors['apps'] = 'A fully managed policy needs at least one enabled app with install type FORCE_INSTALLED, otherwise the phone would finish setup with nothing installed.';
        }

        foreach ($enabled as $app) {
            if (! preg_match(self::PACKAGE_NAME_PATTERN, $app->package_name)) {
                $errors['apps'] = "“{$app->package_name}” is not a valid Android package name.";
                break;
            }
        }

        foreach ($this->frpEmails($policy) as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['frp_admin_emails'] = "“{$email}” is not a valid Google account email for factory reset protection.";
                break;
            }
        }

        if ($policy->password_min_length && ! $policy->password_quality) {
            $errors['password_quality'] = 'Choose a password quality: Google only enforces the minimum length together with one.';
        }

        if ($policy->system_update_type === 'WINDOWED') {
            $start = $policy->system_update_start_minutes;
            $end = $policy->system_update_end_minutes;

            if ($start === null || $end === null || $start > 1439 || $end > 1439) {
                $errors['system_update_start_minutes'] = 'A windowed system update needs a start and end time within one day.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Advice that does not block publishing.
     *
     * @return list<string>
     */
    public function warnings(MdmPolicy $policy): array
    {
        $warnings = [];

        if ($this->frpEmails($policy) === []) {
            $warnings[] = 'No factory reset protection accounts are set. Without them a stolen phone that is wiped can be set up again by anyone.';
        }

        if (! $policy->factory_reset_disabled) {
            $warnings[] = 'Factory reset is allowed on the device, so a thief can wipe the phone from Settings.';
        }

        if ($policy->play_store_mode !== 'WHITELIST') {
            $warnings[] = 'The Play Store is not in allow-list mode, so apps outside this policy are not removed.';
        }

        if ($policy->untrusted_apps_policy !== 'DISALLOW_INSTALL') {
            $warnings[] = 'Apps from unknown sources are allowed.';
        }

        if ($policy->developer_settings !== 'DEVELOPER_SETTINGS_DISABLED') {
            $warnings[] = 'Developer settings are allowed on the device.';
        }

        return $warnings;
    }

    /**
     * What "Publish to Google" would change: the payload about to be sent against the one Google last accepted.
     *
     * @return list<array{path: string, type: string, before: ?string, after: ?string}>
     */
    public function diff(MdmPolicy $policy): array
    {
        $before = $this->flatten($policy->last_published_payload ?? []);
        $after = $this->flatten($this->toAmapiPayload($policy));
        $changes = [];

        foreach ($after as $path => $value) {
            if (! array_key_exists($path, $before)) {
                $changes[] = ['path' => $path, 'type' => 'added', 'before' => null, 'after' => $value];
            } elseif ($before[$path] !== $value) {
                $changes[] = ['path' => $path, 'type' => 'changed', 'before' => $before[$path], 'after' => $value];
            }
        }

        foreach ($before as $path => $value) {
            if (! array_key_exists($path, $after)) {
                $changes[] = ['path' => $path, 'type' => 'removed', 'before' => $value, 'after' => null];
            }
        }

        return $changes;
    }

    public function hasUnpublishedChanges(MdmPolicy $policy): bool
    {
        if (! $policy->isPublished()) {
            return true;
        }

        return $this->diff($policy) !== [];
    }

    public function policyResourceName(MdmPolicy $policy): string
    {
        return $policy->google_policy_name ?: $this->settings->enterpriseName().'/policies/gwl-'.$policy->id;
    }

    /**
     * Validate, PATCH to Google, then record what Google accepted (payload, version, who, when) and audit it.
     *
     * @throws ValidationException
     */
    public function publish(MdmPolicy $policy, User $actor): MdmPolicy
    {
        $this->validateForPublish($policy);

        $name = $this->policyResourceName($policy);
        $payload = $this->toAmapiPayload($policy);
        $previous = $policy->last_published_payload;
        $changes = $this->diff($policy);

        $this->gateway->patchPolicy($name, $payload, implode(',', self::MANAGED_FIELDS));

        $policy->update([
            'google_policy_name' => $name,
            'last_published_payload' => $payload,
            'published_at' => now(),
            'published_by' => $actor->id,
            'version' => $policy->version + 1,
        ]);

        AuditLog::record(
            action: 'mdm_policy_published',
            module: Permission::MODULE_ASSETS,
            targetType: 'mdm_policies',
            targetId: $policy->id,
            old: $previous,
            new: $payload,
            metadata: [
                'policy' => $policy->name,
                'google_policy_name' => $name,
                'version' => $policy->version,
                'changed_paths' => Arr::pluck($changes, 'path'),
            ],
        );

        return $policy->refresh();
    }

    /** @return list<string> */
    private function frpEmails(MdmPolicy $policy): array
    {
        return collect($policy->frp_admin_emails ?? [])
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function systemUpdate(MdmPolicy $policy): array
    {
        $update = ['type' => $policy->system_update_type];

        if ($policy->system_update_type === 'WINDOWED') {
            $update['startMinutes'] = (int) $policy->system_update_start_minutes;
            $update['endMinutes'] = (int) $policy->system_update_end_minutes;
        }

        return $update;
    }

    /**
     * Dot-path each leaf so two payloads can be compared field by field. Applications are keyed by package name
     * (not list position) so re-ordering is not reported as a change; scalar lists are compared as sorted sets.
     *
     * @return array<string, string>
     */
    private function flatten(array $payload): array
    {
        $flat = [];

        foreach ($payload['applications'] ?? [] as $app) {
            $flat['applications['.($app['packageName'] ?? '?').']'] = trim(($app['installType'] ?? '').' '.($app['defaultPermissionPolicy'] ?? ''));
        }

        unset($payload['applications']);

        $walk = function (array $node, string $prefix) use (&$walk, &$flat): void {
            foreach ($node as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

                if (is_array($value) && $value !== [] && Arr::isAssoc($value)) {
                    $walk($value, $path);
                } elseif (is_array($value)) {
                    // A list: a scalar set (frpAdminEmails) or a list of objects (passwordPolicies).
                    $items = array_map(fn ($item) => is_array($item) ? json_encode($item) : (string) $item, $value);
                    sort($items);
                    $flat[$path] = implode(', ', $items);
                } else {
                    $flat[$path] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                }
            }
        };

        $walk($payload, '');

        return $flat;
    }
}
