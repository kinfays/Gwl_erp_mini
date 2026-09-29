<?php

namespace App\Livewire\Assets\Mdm;

use App\Livewire\Assets\Mdm\Concerns\AuthorizesMdm;
use App\Models\AuditLog;
use App\Models\MdmPolicy;
use App\Models\MdmPolicyApp;
use App\Models\Permission;
use App\Services\Assets\Mdm\Exceptions\MdmException;
use App\Services\Assets\Mdm\PolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create / edit one policy and publish it to Google. "Publish to Google" first saves, then shows a diff of exactly what
 * would change against the version Google last accepted, plus non-blocking warnings, before anything is sent.
 */
class PolicyEditor extends Component
{
    use AuthorizesMdm;

    #[Locked]
    public ?int $policyId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $frpEmailsText = '';

    public string $windowStart = '02:00';

    public string $windowEnd = '04:00';

    /** @var list<array{package_name: string, app_name: string, install_type: string, default_permission_policy: string, is_enabled: bool}> */
    public array $apps = [];

    #[Locked]
    public bool $showPublish = false;

    /** @var list<array{path: string, type: string, before: ?string, after: ?string}> */
    #[Locked]
    public array $diff = [];

    /** @var list<string> */
    #[Locked]
    public array $warnings = [];

    public function mount(?int $policyId = null): void
    {
        $this->bootMdmScreen('assets.mdm_manage_policies');

        if ($policyId !== null) {
            $policy = MdmPolicy::query()->with('apps')->findOrFail($policyId);
            $this->policyId = $policy->id;
            $this->fillFrom($policy);

            return;
        }

        $this->form = $this->defaults();
        $this->apps = [$this->blankApp()];
    }

    public function addApp(): void
    {
        $this->apps[] = $this->blankApp();
    }

    public function removeApp(int $index): void
    {
        unset($this->apps[$index]);
        $this->apps = array_values($this->apps);
    }

    public function save(): void
    {
        $this->persist();

        $this->dispatch('toast', type: 'success', message: 'Policy saved. Publish it to Google to apply the changes to phones.');
    }

    /** Save, validate for publishing, and open the diff. Nothing reaches Google here. */
    public function preparePublish(PolicyService $policies): void
    {
        $policy = $this->persist();

        try {
            $policies->validateForPublish($policy);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->diff = $policies->diff($policy);
        $this->warnings = $policies->warnings($policy);
        $this->showPublish = true;
    }

    public function cancelPublish(): void
    {
        $this->showPublish = false;
        $this->diff = [];
        $this->warnings = [];
    }

    public function publish(PolicyService $policies): void
    {
        $this->authorizeMdm('assets.mdm_manage_policies');

        $policy = MdmPolicy::query()->findOrFail((int) $this->policyId);

        try {
            $policies->publish($policy, $this->mdmUser());
        } catch (ValidationException $e) {
            $this->cancelPublish();
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (MdmException $e) {
            $this->addError('publish', $e->getMessage());

            return;
        }

        $this->cancelPublish();
        $this->dispatch('toast', type: 'success', message: 'Policy published to Google. Enrolled phones pick it up on their next check-in.');
    }

    public function render()
    {
        return view('livewire.assets.mdm.policy-editor', [
            'policy' => $this->policyId ? MdmPolicy::query()->find($this->policyId) : null,
            'installTypes' => MdmPolicyApp::INSTALL_TYPES,
            'permissionPolicies' => MdmPolicyApp::PERMISSION_POLICIES,
        ]);
    }

    /** Validate and store the form; returns the saved policy. */
    private function persist(): MdmPolicy
    {
        $this->authorizeMdm('assets.mdm_manage_policies');

        $this->validate($this->rules(), $this->messages());

        $emails = $this->parseEmails();

        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['frpEmailsText' => "“{$email}” is not a valid email address."]);
            }
        }

        $attributes = [
            'name' => trim($this->form['name']),
            'description' => filled($this->form['description'] ?? null) ? trim($this->form['description']) : null,
            'play_store_mode' => $this->form['play_store_mode'],
            'install_apps_disabled' => (bool) $this->form['install_apps_disabled'],
            'uninstall_apps_disabled' => (bool) $this->form['uninstall_apps_disabled'],
            'factory_reset_disabled' => (bool) $this->form['factory_reset_disabled'],
            'add_user_disabled' => (bool) $this->form['add_user_disabled'],
            'screen_capture_disabled' => (bool) $this->form['screen_capture_disabled'],
            'camera_access' => $this->form['camera_access'],
            'usb_data_access' => $this->form['usb_data_access'],
            'untrusted_apps_policy' => $this->form['untrusted_apps_policy'],
            'developer_settings' => $this->form['developer_settings'],
            'password_min_length' => filled($this->form['password_min_length'] ?? null) ? (int) $this->form['password_min_length'] : null,
            'password_quality' => filled($this->form['password_quality'] ?? null) ? $this->form['password_quality'] : null,
            'system_update_type' => $this->form['system_update_type'],
            'system_update_start_minutes' => $this->form['system_update_type'] === 'WINDOWED' ? $this->toMinutes($this->windowStart) : null,
            'system_update_end_minutes' => $this->form['system_update_type'] === 'WINDOWED' ? $this->toMinutes($this->windowEnd) : null,
            'frp_admin_emails' => $emails,
        ];

        return DB::transaction(function () use ($attributes) {
            $existing = $this->policyId ? MdmPolicy::query()->with('apps')->findOrFail($this->policyId) : null;
            $old = $existing ? $this->snapshot($existing, array_keys($attributes)) : null;

            $policy = $existing
                ? tap($existing)->update($attributes)
                : MdmPolicy::query()->create($attributes + ['created_by' => $this->mdmUser()->id]);

            $this->syncApps($policy);
            $policy->load('apps');

            AuditLog::record(
                action: $existing ? 'mdm_policy_updated' : 'mdm_policy_created',
                module: Permission::MODULE_ASSETS,
                targetType: 'mdm_policies',
                targetId: $policy->id,
                old: $old,
                new: $this->snapshot($policy, array_keys($attributes)),
            );

            $this->policyId = $policy->id;

            return $policy;
        });
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function snapshot(MdmPolicy $policy, array $fields): array
    {
        return $policy->only($fields) + [
            'apps' => $policy->apps->map->only(['package_name', 'install_type', 'is_enabled'])->values()->all(),
        ];
    }

    private function syncApps(MdmPolicy $policy): void
    {
        $keep = [];

        foreach ($this->apps as $row) {
            $package = trim($row['package_name']);

            $policy->apps()->updateOrCreate(['package_name' => $package], [
                'app_name' => filled($row['app_name'] ?? null) ? trim($row['app_name']) : null,
                'install_type' => $row['install_type'],
                'default_permission_policy' => filled($row['default_permission_policy'] ?? null) ? $row['default_permission_policy'] : null,
                'is_enabled' => (bool) $row['is_enabled'],
            ]);

            $keep[] = $package;
        }

        $policy->apps()->whereNotIn('package_name', $keep)->delete();
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:120', Rule::unique('mdm_policies', 'name')->ignore($this->policyId)],
            'form.description' => ['nullable', 'string', 'max:500'],
            'form.play_store_mode' => ['required', Rule::in(array_keys(MdmPolicy::PLAY_STORE_MODES))],
            'form.install_apps_disabled' => ['boolean'],
            'form.uninstall_apps_disabled' => ['boolean'],
            'form.factory_reset_disabled' => ['boolean'],
            'form.add_user_disabled' => ['boolean'],
            'form.screen_capture_disabled' => ['boolean'],
            'form.camera_access' => ['required', Rule::in(array_keys(MdmPolicy::CAMERA_ACCESS))],
            'form.usb_data_access' => ['required', Rule::in(array_keys(MdmPolicy::USB_DATA_ACCESS))],
            'form.untrusted_apps_policy' => ['required', Rule::in(array_keys(MdmPolicy::UNTRUSTED_APPS))],
            'form.developer_settings' => ['required', Rule::in(array_keys(MdmPolicy::DEVELOPER_SETTINGS))],
            'form.password_min_length' => ['nullable', 'integer', 'between:4,64'],
            'form.password_quality' => ['nullable', Rule::in(array_keys(MdmPolicy::PASSWORD_QUALITIES))],
            'form.system_update_type' => ['required', Rule::in(array_keys(MdmPolicy::SYSTEM_UPDATE_TYPES))],
            'windowStart' => ['required_if:form.system_update_type,WINDOWED', 'nullable', 'date_format:H:i'],
            'windowEnd' => ['required_if:form.system_update_type,WINDOWED', 'nullable', 'date_format:H:i'],
            'frpEmailsText' => ['nullable', 'string', 'max:2000'],
            'apps' => ['array', 'max:300'],
            'apps.*.package_name' => ['required', 'string', 'max:255', 'regex:'.PolicyService::PACKAGE_NAME_PATTERN, 'distinct'],
            'apps.*.app_name' => ['nullable', 'string', 'max:120'],
            'apps.*.install_type' => ['required', Rule::in(array_keys(MdmPolicyApp::INSTALL_TYPES))],
            'apps.*.default_permission_policy' => ['nullable', Rule::in(array_keys(MdmPolicyApp::PERMISSION_POLICIES))],
            'apps.*.is_enabled' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'apps.*.package_name.required' => 'Every app needs a package name (or remove the empty row).',
            'apps.*.package_name.regex' => 'That is not a valid Android package name, e.g. com.whatsapp.w4b.',
            'apps.*.package_name.distinct' => 'This package is listed twice.',
        ];
    }

    private function fillFrom(MdmPolicy $policy): void
    {
        $this->form = [
            'name' => $policy->name,
            'description' => (string) $policy->description,
            'play_store_mode' => $policy->play_store_mode,
            'install_apps_disabled' => $policy->install_apps_disabled,
            'uninstall_apps_disabled' => $policy->uninstall_apps_disabled,
            'factory_reset_disabled' => $policy->factory_reset_disabled,
            'add_user_disabled' => $policy->add_user_disabled,
            'screen_capture_disabled' => $policy->screen_capture_disabled,
            'camera_access' => $policy->camera_access,
            'usb_data_access' => $policy->usb_data_access,
            'untrusted_apps_policy' => $policy->untrusted_apps_policy,
            'developer_settings' => $policy->developer_settings,
            'password_min_length' => $policy->password_min_length,
            'password_quality' => (string) $policy->password_quality,
            'system_update_type' => $policy->system_update_type,
        ];

        $this->frpEmailsText = implode("\n", $policy->frp_admin_emails ?? []);
        $this->windowStart = $this->fromMinutes($policy->system_update_start_minutes, '02:00');
        $this->windowEnd = $this->fromMinutes($policy->system_update_end_minutes, '04:00');

        $this->apps = $policy->apps->map(fn (MdmPolicyApp $app) => [
            'package_name' => $app->package_name,
            'app_name' => (string) $app->app_name,
            'install_type' => $app->install_type,
            'default_permission_policy' => (string) $app->default_permission_policy,
            'is_enabled' => $app->is_enabled,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'name' => '',
            'description' => '',
            'play_store_mode' => 'WHITELIST',
            'install_apps_disabled' => true,
            'uninstall_apps_disabled' => true,
            'factory_reset_disabled' => true,
            'add_user_disabled' => true,
            'screen_capture_disabled' => false,
            'camera_access' => 'CAMERA_ACCESS_USER_CHOICE',
            'usb_data_access' => 'DISALLOW_USB_FILE_TRANSFER',
            'untrusted_apps_policy' => 'DISALLOW_INSTALL',
            'developer_settings' => 'DEVELOPER_SETTINGS_DISABLED',
            'password_min_length' => null,
            'password_quality' => '',
            'system_update_type' => 'AUTOMATIC',
        ];
    }

    /** @return array{package_name: string, app_name: string, install_type: string, default_permission_policy: string, is_enabled: bool} */
    private function blankApp(): array
    {
        return ['package_name' => '', 'app_name' => '', 'install_type' => MdmPolicyApp::INSTALL_TYPE_FORCE_INSTALLED, 'default_permission_policy' => '', 'is_enabled' => true];
    }

    /** @return list<string> */
    private function parseEmails(): array
    {
        return collect(preg_split('/[\s,;]+/', $this->frpEmailsText) ?: [])
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [0, 0]);

        return $hours * 60 + $minutes;
    }

    private function fromMinutes(?int $minutes, string $fallback): string
    {
        return $minutes === null ? $fallback : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
