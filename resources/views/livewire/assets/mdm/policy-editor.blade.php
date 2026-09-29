<div>
    <x-ui.page-header :title="$policyId ? 'Edit Policy' : 'New Policy'" description="Saved here first; nothing reaches Google until you publish.">
        <x-slot:actions>
            <a href="{{ route('assets.mdm.policies') }}" class="btn btn-secondary">
                <x-ui.icon name="arrow-left" />
                All policies
            </a>
            <button type="button" wire:click="save" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="save">Save</button>
            <button type="button" wire:click="preparePublish" class="btn btn-primary" wire:loading.attr="disabled" wire:target="preparePublish">
                <x-ui.icon name="upload" />
                Publish to Google
            </button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($policy?->isPublished())
        <x-ui.alert tone="info" class="dash-row">
            Published as <span class="mono">{{ $policy->google_policy_name }}</span>, version {{ $policy->version }}, {{ $policy->published_at?->diffForHumans() }}.
        </x-ui.alert>
    @endif

    @error('publish')
        <x-ui.alert tone="danger" title="Publishing failed" class="dash-row" role="alert">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.card title="General" class="dash-row">
        <div class="ui-form-grid">
            <x-ui.input label="Policy name" wire:model="form.name" required />
            <x-ui.input label="Description" wire:model="form.description" />
        </div>
    </x-ui.card>

    <div class="ui-grid ui-grid-2 dash-row">
        <x-ui.card title="Apps and Play Store">
            <div class="ui-form-grid">
                <div class="span-2">
                    <x-ui.select label="Play Store mode" wire:model="form.play_store_mode" hint="Allow-list removes every app that is not in this policy.">
                        @foreach (\App\Models\MdmPolicy::PLAY_STORE_MODES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <div class="span-2"><x-ui.toggle label="Block users from installing apps" wire:model="form.install_apps_disabled" /></div>
                <div class="span-2"><x-ui.toggle label="Block users from uninstalling apps" wire:model="form.uninstall_apps_disabled" /></div>
            </div>
        </x-ui.card>

        <x-ui.card title="Device restrictions">
            <div class="ui-form-grid">
                <div class="span-2"><x-ui.toggle label="Disable factory reset from Settings" wire:model="form.factory_reset_disabled" /></div>
                <div class="span-2"><x-ui.toggle label="Block adding other users" wire:model="form.add_user_disabled" /></div>
                <div class="span-2"><x-ui.toggle label="Block screenshots and screen recording" wire:model="form.screen_capture_disabled" /></div>
                <x-ui.select label="Camera" wire:model="form.camera_access">
                    @foreach (\App\Models\MdmPolicy::CAMERA_ACCESS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="USB data" wire:model="form.usb_data_access">
                    @foreach (\App\Models\MdmPolicy::USB_DATA_ACCESS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Apps from unknown sources" wire:model="form.untrusted_apps_policy">
                    @foreach (\App\Models\MdmPolicy::UNTRUSTED_APPS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select label="Developer settings" wire:model="form.developer_settings">
                    @foreach (\App\Models\MdmPolicy::DEVELOPER_SETTINGS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            </div>
        </x-ui.card>
    </div>

    <div class="ui-grid ui-grid-2 dash-row">
        <x-ui.card title="Anti-theft" description="Factory reset protection is the main control against a stolen phone.">
            <x-ui.textarea label="Factory reset protection accounts" wire:model="frpEmailsText" rows="3" hint="One Google account email per line. After a factory reset the phone needs one of these accounts to be set up again." />
            @error('frp_admin_emails') <p class="ui-error">{{ $message }}</p> @enderror
        </x-ui.card>

        <x-ui.card title="Screen lock and updates">
            <div class="ui-form-grid">
                <x-ui.select label="Passcode quality" wire:model="form.password_quality">
                    @foreach (\App\Models\MdmPolicy::PASSWORD_QUALITIES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Minimum length" type="number" min="4" max="64" wire:model="form.password_min_length" />
                <div class="span-2">
                    <x-ui.select label="System updates" wire:model.live="form.system_update_type">
                        @foreach (\App\Models\MdmPolicy::SYSTEM_UPDATE_TYPES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                @if (($form['system_update_type'] ?? '') === 'WINDOWED')
                    <x-ui.input label="Window starts" type="time" wire:model="windowStart" />
                    <x-ui.input label="Window ends" type="time" wire:model="windowEnd" />
                @endif
            </div>
            @error('password_quality') <p class="ui-error">{{ $message }}</p> @enderror
            @error('system_update_start_minutes') <p class="ui-error">{{ $message }}</p> @enderror
        </x-ui.card>
    </div>

    <x-ui.card title="App catalog" description="Package names come from the Play Store address of the app (…?id=com.whatsapp.w4b)." :padded="false">
        <x-ui.table label="App catalog" :sticky="false">
            <x-slot:head>
                <tr>
                    <th>Package name</th>
                    <th>Label</th>
                    <th>Install type</th>
                    <th>Permissions</th>
                    <th>On</th>
                    <th class="actions"><span class="sr-only-text">Remove</span></th>
                </tr>
            </x-slot:head>

            @foreach ($apps as $index => $app)
                <tr wire:key="app-row-{{ $index }}">
                    <td>
                        <input type="text" wire:model="apps.{{ $index }}.package_name" class="form-input mono" placeholder="com.example.app" aria-label="Package name" autocomplete="off">
                        @error("apps.$index.package_name") <p class="ui-error">{{ $message }}</p> @enderror
                    </td>
                    <td><input type="text" wire:model="apps.{{ $index }}.app_name" class="form-input" placeholder="Optional" aria-label="App label"></td>
                    <td>
                        <select wire:model="apps.{{ $index }}.install_type" class="form-input" aria-label="Install type">
                            @foreach ($installTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <select wire:model="apps.{{ $index }}.default_permission_policy" class="form-input" aria-label="Runtime permissions">
                            @foreach ($permissionPolicies as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td><input type="checkbox" wire:model="apps.{{ $index }}.is_enabled" aria-label="Include in policy"></td>
                    <td class="actions">
                        <button type="button" wire:click="removeApp({{ $index }})" class="btn btn-ghost btn-sm btn-icon" title="Remove" aria-label="Remove app row {{ $index + 1 }}">
                            <x-ui.icon name="trash-2" />
                        </button>
                    </td>
                </tr>
            @endforeach

            <x-slot:footer>
                <button type="button" wire:click="addApp" class="btn btn-secondary btn-sm">
                    <x-ui.icon name="plus" />
                    Add app
                </button>
                @error('apps') <p class="ui-error">{{ $message }}</p> @enderror
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>

    @if ($showPublish)
        <x-ui.modal title="Publish to Google?" description="These are the changes compared with the version Google has now." close="cancelPublish()" size="lg" icon="upload">
            @foreach ($warnings as $warning)
                <x-ui.alert tone="warning">{{ $warning }}</x-ui.alert>
            @endforeach

            @if ($diff === [])
                <p class="ui-hint">No differences: Google already has exactly this policy.</p>
            @else
                <ul class="mdm-diff">
                    @foreach ($diff as $change)
                        <li class="mdm-diff-{{ $change['type'] }}">
                            <span class="mdm-diff-path mono">{{ $change['path'] }}</span>
                            @if ($change['type'] === 'added')
                                <span>added <strong class="mono">{{ $change['after'] === '' ? '(empty)' : $change['after'] }}</strong></span>
                            @elseif ($change['type'] === 'removed')
                                <span>removed <span class="mono">{{ $change['before'] === '' ? '(empty)' : $change['before'] }}</span></span>
                            @else
                                <span><span class="mono">{{ $change['before'] === '' ? '(empty)' : $change['before'] }}</span> → <strong class="mono">{{ $change['after'] === '' ? '(empty)' : $change['after'] }}</strong></span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @error('publish') <p class="ui-error">{{ $message }}</p> @enderror

            <x-slot:footer>
                <button type="button" wire:click="cancelPublish" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="publish" class="btn btn-primary" wire:loading.attr="disabled" wire:target="publish">Publish to Google</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
