<?php

namespace App\Services\Assets\Mdm;

use App\Models\Permission;
use App\Services\Assets\Mdm\Exceptions\MdmException;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;

/**
 * One-time bootstrap: bind the Google project to an Android Enterprise, then point that enterprise at the
 * Pub/Sub topic so device notifications start flowing. Run from the mdm:enterprise-* commands and the
 * signup-callback route.
 */
class EnterpriseService
{
    /** signupUrls.create returns a `name` that enterprises.create needs later; it must survive between the two steps. */
    public const SIGNUP_URL_CACHE_KEY = 'mdm.enterprise.signup_url_name';

    public const SIGNUP_URL_TTL_HOURS = 24;

    /** Notification types the ERP handles (the ENROLLMENT/STATUS_REPORT/COMMAND/USAGE_LOGS set). */
    public const NOTIFICATION_TYPES = ['ENROLLMENT', 'STATUS_REPORT', 'COMMAND', 'USAGE_LOGS'];

    public function __construct(
        private readonly AndroidManagementGateway $gateway,
        private readonly MdmSettings $settings,
    ) {}

    /**
     * @return array{url: string, name: string, callback_url: string}
     */
    public function startSignup(string $callbackUrl): array
    {
        $signup = $this->gateway->createSignupUrl($this->settings->projectId(), $callbackUrl);

        if (empty($signup['name']) || empty($signup['url'])) {
            throw new MdmException('Google returned an incomplete signup URL.');
        }

        Cache::put(self::SIGNUP_URL_CACHE_KEY, $signup['name'], now()->addHours(self::SIGNUP_URL_TTL_HOURS));

        Audit::log('mdm_enterprise_signup_started', Permission::MODULE_ASSETS, null, null, ['callback_url' => $callbackUrl]);

        return ['url' => $signup['url'], 'name' => $signup['name'], 'callback_url' => $callbackUrl];
    }

    /**
     * Turn the token Google appended to the callback URL into an enterprise, wiring the Pub/Sub topic in one step
     * when it is configured. Returns the enterprise resource name to paste into .env.
     */
    public function completeSignup(string $enterpriseToken, ?string $signupUrlName = null, string $displayName = 'GWL'): string
    {
        $signupUrlName ??= Cache::get(self::SIGNUP_URL_CACHE_KEY);

        if (! is_string($signupUrlName) || $signupUrlName === '') {
            throw new MdmException('The signup URL name is unknown (the 24h window may have passed). Run mdm:enterprise-signup again, or pass --signup-url-name.');
        }

        $body = ['enterpriseDisplayName' => $displayName];

        if (filled(config('gwl.mdm_pubsub_topic'))) {
            $body['pubsubTopic'] = $this->settings->pubsubTopic();
            $body['enabledNotificationTypes'] = self::NOTIFICATION_TYPES;
        }

        $enterprise = $this->gateway->createEnterprise($this->settings->projectId(), $signupUrlName, $enterpriseToken, $body);

        $name = $enterprise['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new MdmException('Google did not return an enterprise name.');
        }

        Cache::forget(self::SIGNUP_URL_CACHE_KEY);

        Audit::log('mdm_enterprise_created', Permission::MODULE_ASSETS, null, null, ['enterprise' => $name]);

        return $name;
    }

    /**
     * Point an existing enterprise at the configured Pub/Sub topic. Without this no ENROLLMENT / STATUS_REPORT /
     * COMMAND events are ever published, so the ERP would never hear about a phone.
     *
     * @return array<string, mixed> the updated Enterprise resource
     */
    public function configureNotifications(): array
    {
        $result = $this->gateway->patchEnterprise($this->settings->enterpriseName(), [
            'pubsubTopic' => $this->settings->pubsubTopic(),
            'enabledNotificationTypes' => self::NOTIFICATION_TYPES,
        ], 'pubsubTopic,enabledNotificationTypes');

        Audit::log('mdm_enterprise_notifications_configured', Permission::MODULE_ASSETS, null, null, [
            'enterprise' => $this->settings->enterpriseName(),
            'topic' => $this->settings->pubsubTopic(),
        ]);

        return $result;
    }
}
