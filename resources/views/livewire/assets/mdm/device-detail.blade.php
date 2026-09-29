<div>
    @php
        $asset = $device->asset;
        $title = $asset?->asset_name ?? 'Unlinked device';
    @endphp

    <x-ui.page-header :title="$title" :description="collect([$asset?->serial_number, $asset?->assetModel?->name ?? ($device->hardware_info['model'] ?? null)])->filter()->join(' · ') ?: 'Managed Android phone'">
        <x-slot:actions>
            <a href="{{ route('assets.mdm.devices') }}" class="btn btn-secondary">
                <x-ui.icon name="arrow-left" />
                All phones
            </a>
            @unless ($device->isDeleted())
                <button type="button" wire:click="syncNow" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="syncNow">
                    <x-ui.icon name="refresh-cw" />
                    Sync now
                </button>
            @endunless
        </x-slot:actions>
    </x-ui.page-header>

    @if ($device->isDeleted())
        <x-ui.alert tone="warning" title="Removed from management" class="dash-row">
            This phone has been removed from Android Enterprise (wiped or deleted). To use it again, factory reset it and enroll it with a new QR code.
        </x-ui.alert>
    @endif

    @if ($device->needs_review)
        <x-ui.alert tone="warning" title="Identity needs review" class="dash-row">
            {{ $device->review_reason ?: 'This enrollment could not be verified against the asset record.' }}
            <br>
            Lock, reboot and Lost Mode are disabled until an administrator confirms it.
            <x-slot:actions>
                @if ($canReview)
                    <button type="button" wire:click="confirmIdentity" wire:confirm="Confirm that this phone really is the linked asset?" class="btn btn-secondary btn-sm">
                        Confirm identity
                    </button>
                @endif
            </x-slot:actions>
        </x-ui.alert>
    @endif

    @if ($device->is_lost)
        <x-ui.alert tone="danger" title="Lost Mode is on" class="dash-row">
            Started {{ $device->lost_at?->diffForHumans() ?? 'recently' }}. The phone shows the lost message and is locked.
        </x-ui.alert>
    @endif

    @if ($canCommand || $canWipe)
        <x-ui.card title="Actions" class="dash-row">
            <div class="ui-actions-row">
                @if ($canCommand)
                    <button type="button" wire:click="openModal('lock')" @disabled($device->needs_review) class="btn btn-secondary">
                        <x-ui.icon name="key-round" /> Lock
                    </button>
                    <button type="button" wire:click="openModal('reboot')" @disabled($device->needs_review) class="btn btn-secondary">
                        <x-ui.icon name="refresh-cw" /> Reboot
                    </button>
                    <button type="button" wire:click="openModal('reset')" @disabled($device->needs_review) class="btn btn-secondary">
                        <x-ui.icon name="pencil" /> Reset passcode
                    </button>
                    @if ($device->is_lost)
                        <button type="button" wire:click="openModal('lost-stop')" @disabled($device->needs_review) class="btn btn-secondary">
                            <x-ui.icon name="circle-check" /> Stop Lost Mode
                        </button>
                    @else
                        <button type="button" wire:click="openModal('lost-start')" @disabled($device->needs_review) class="btn btn-secondary">
                            <x-ui.icon name="map-pin" /> Start Lost Mode
                        </button>
                    @endif
                @endif
                @if ($canWipe)
                    <button type="button" wire:click="openModal('wipe')" class="btn btn-danger">
                        <x-ui.icon name="trash-2" /> Wipe phone
                    </button>
                @endif
            </div>
        </x-ui.card>
    @endif

    <div class="ui-grid ui-grid-2 dash-row">
        <x-ui.card title="Identity">
            <dl class="ui-dl">
                <div><dt>Asset tag</dt><dd>{{ $asset?->asset_name ?? '—' }}</dd></div>
                <div><dt>Serial number</dt><dd class="mono">{{ $asset?->serial_number ?: '—' }}</dd></div>
                <div><dt>IMEI</dt><dd class="mono">{{ $asset?->imei ?: '—' }}</dd></div>
                <div><dt>Assigned to</dt><dd>{{ $asset?->assignedTo?->full_name ?? 'Unassigned' }}</dd></div>
                <div><dt>Region</dt><dd>{{ $asset?->region?->region_name ?? '—' }}</dd></div>
                <div><dt>Location</dt><dd>{{ $asset?->district?->district_name ?? '—' }}</dd></div>
                <div><dt>Phone number</dt><dd class="mono">{{ $asset?->device_phone_number ?: '—' }}</dd></div>
                <div><dt>Asset record</dt><dd>@if ($asset)<x-ui.status-pill domain="asset" :status="$asset->status" />@else — @endif</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Device">
            <dl class="ui-dl">
                <div><dt>Management</dt><dd>{{ $device->managementModeLabel() }}</dd></div>
                <div><dt>State</dt><dd>{{ \App\Services\Assets\Mdm\Labels::enum($device->state) }}</dd></div>
                <div><dt>Android</dt><dd>{{ $device->android_version ?: '—' }}</dd></div>
                <div><dt>Security patch</dt><dd>{{ $device->security_patch_level ?: '—' }}</dd></div>
                <div><dt>Reported model</dt><dd>{{ collect([$device->hardware_info['manufacturer'] ?? null, $device->hardware_info['model'] ?? null])->filter()->join(' ') ?: '—' }}</dd></div>
                <div><dt>Reported serial</dt><dd class="mono">{{ $device->hardware_info['serialNumber'] ?? '—' }}</dd></div>
                <div><dt>Enrolled</dt><dd>{{ $device->enrolled_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div>
                    <dt>Last check-in</dt>
                    <dd @class(['cell-muted' => ! $device->last_status_report_at || $device->last_status_report_at->lt($staleBefore)])>
                        {{ $device->last_status_report_at?->diffForHumans() ?? 'Never' }}
                    </dd>
                </div>
            </dl>
        </x-ui.card>
    </div>

    <x-ui.card title="Policy compliance" class="dash-row">
        <dl class="ui-dl">
            <div>
                <dt>Policy</dt>
                <dd>{{ $device->policy?->name ?? '—' }} @if ($device->policy)<span class="ui-hint">v{{ $device->policy->version }}</span>@endif</dd>
            </div>
            <div>
                <dt>Status</dt>
                <dd>
                    @if ($device->policy_compliant === null)
                        <x-ui.status-pill tone="muted" label="Awaiting first report" />
                    @elseif ($device->policy_compliant)
                        <x-ui.status-pill tone="success" label="Compliant" />
                    @else
                        <x-ui.status-pill tone="warning" label="Non-compliant" />
                    @endif
                </dd>
            </div>
        </dl>

        @if (! $device->policy_compliant && $device->non_compliance)
            <ul class="mdm-reasons">
                @foreach ($device->non_compliance as $reason)
                    <li>
                        <strong>{{ \App\Services\Assets\Mdm\Labels::setting($reason['settingName'] ?? null) }}</strong>
                        — {{ \App\Services\Assets\Mdm\Labels::enum($reason['nonComplianceReason'] ?? null, 'Not compliant') }}
                        @if (! empty($reason['packageName']))
                            <span class="mono ui-hint">{{ $reason['packageName'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.card title="Installed apps" :description="count($device->application_reports ?? []).' reported by the phone.'" :padded="false" class="dash-row">
        <x-ui.table label="Installed apps" :sticky="false">
            <x-slot:head>
                <tr>
                    <th>App</th>
                    <th>Package</th>
                    <th>Version</th>
                    <th>State</th>
                </tr>
            </x-slot:head>
            @forelse (collect($device->application_reports ?? [])->take(300) as $app)
                <tr>
                    <td>{{ $app['displayName'] ?? '—' }}</td>
                    <td class="mono">{{ $app['packageName'] ?? '—' }}</td>
                    <td>{{ $app['versionName'] ?? '—' }}</td>
                    <td>{{ \App\Services\Assets\Mdm\Labels::enum($app['state'] ?? null) }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="4" icon="boxes" title="No app report yet." description="The phone sends its app list with its next status report." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Command history" :padded="false">
        <x-ui.table label="Command history" :sticky="false">
            <x-slot:head>
                <tr>
                    <th>Command</th>
                    <th>Requested by</th>
                    <th>Status</th>
                    <th>Requested</th>
                    <th>Detail</th>
                </tr>
            </x-slot:head>
            @forelse ($commands as $command)
                <tr wire:key="cmd-{{ $command->id }}">
                    <td>{{ $command->typeLabel() }}</td>
                    <td class="nowrap">{{ $command->requester?->full_name ?? 'System' }}</td>
                    <td><x-ui.status-pill domain="mdm-command" :status="$command->status" /></td>
                    <td class="nowrap">{{ $command->requested_at?->format('d M Y H:i') }}</td>
                    <td class="ui-hint">{{ $command->error }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" icon="send" title="No commands have been sent to this phone." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    {{-- ------------------------------------------------------------------ modals ------------------------------------------------------------------ --}}

    @if ($modal === 'lock')
        <x-ui.modal title="Lock this phone?" close="closeModal()" icon="key-round">
            <p>The phone locks immediately, as if its screen timeout had expired.</p>
            <x-assets.mdm-identity :device="$device" />
            @error('command') <p class="ui-error">{{ $message }}</p> @enderror
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="lock" class="btn btn-primary" wire:loading.attr="disabled" wire:target="lock">Lock phone</button>
            </x-slot:footer>
        </x-ui.modal>
    @elseif ($modal === 'reboot')
        <x-ui.modal title="Reboot this phone?" close="closeModal()" icon="refresh-cw">
            <p>The phone restarts. Anything the person is doing on it is interrupted.</p>
            <x-assets.mdm-identity :device="$device" />
            @error('command') <p class="ui-error">{{ $message }}</p> @enderror
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="reboot" class="btn btn-primary" wire:loading.attr="disabled" wire:target="reboot">Reboot phone</button>
            </x-slot:footer>
        </x-ui.modal>
    @elseif ($modal === 'reset')
        <x-ui.modal title="Reset the phone's passcode" close="closeModal()" icon="pencil">
            <p>Sets a new screen-lock passcode. The person must enter it to unlock the phone. It is not stored or logged.</p>
            <x-assets.mdm-identity :device="$device" />
            <x-ui.input label="New passcode" type="password" wire:model="resetPasscode" autocomplete="new-password" hint="6 to 64 characters." />
            @error('command') <p class="ui-error">{{ $message }}</p> @enderror
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="resetPasscodeNow" class="btn btn-primary" wire:loading.attr="disabled" wire:target="resetPasscodeNow">Reset passcode</button>
            </x-slot:footer>
        </x-ui.modal>
    @elseif ($modal === 'lost-start')
        <x-ui.modal title="Start Lost Mode?" description="The phone is locked and shows this message and number." close="closeModal()" icon="map-pin" tone="danger">
            <x-assets.mdm-identity :device="$device" />
            <div class="ui-form-grid">
                <div class="span-2"><x-ui.textarea label="Message" wire:model="lostMessage" rows="3" /></div>
                <x-ui.input label="Phone number to call" type="tel" wire:model="lostPhone" />
                <x-ui.input label="Return address" wire:model="lostAddress" />
            </div>
            @error('command') <p class="ui-error">{{ $message }}</p> @enderror
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="startLostMode" class="btn btn-danger-solid" wire:loading.attr="disabled" wire:target="startLostMode">Start Lost Mode</button>
            </x-slot:footer>
        </x-ui.modal>
    @elseif ($modal === 'lost-stop')
        <x-ui.modal title="Stop Lost Mode?" description="Use this once the phone is back with its owner." close="closeModal()" icon="circle-check">
            <x-assets.mdm-identity :device="$device" />
            @error('command') <p class="ui-error">{{ $message }}</p> @enderror
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="stopLostMode" class="btn btn-primary" wire:loading.attr="disabled" wire:target="stopLostMode">Stop Lost Mode</button>
            </x-slot:footer>
        </x-ui.modal>
    @elseif ($modal === 'wipe')
        <x-ui.modal title="Wipe this phone?" description="This erases everything on the phone and removes it from management. It cannot be undone." close="closeModal()" icon="trash-2" tone="danger">
            <x-assets.mdm-identity :device="$device" />
            <x-ui.alert tone="danger">
                Use Lost Mode first. Wipe a phone only when it has stayed unrecovered for at least 24 hours and you have approval.
            </x-ui.alert>
            <div class="ui-form-grid">
                <div class="span-2">
                    <x-ui.checkbox label="Keep factory reset protection" description="After the wipe the phone still needs a company Google account to be set up again. Leave this on for a lost or stolen phone." wire:model="wipePreserveFrp" />
                </div>
                <div class="span-2">
                    <x-ui.checkbox label="Also erase external storage (SD card)" wire:model="wipeExternalStorage" />
                </div>
                <div class="span-2">
                    <x-ui.checkbox label="I understand this phone will be erased" wire:model="wipeAcknowledge" />
                    @error('wipeAcknowledge') <p class="ui-error">{{ $message }}</p> @enderror
                </div>
                <div class="span-2">
                    <x-ui.input label="Your password" type="password" wire:model="wipePassword" autocomplete="current-password" hint="Re-enter your own password to confirm." />
                </div>
            </div>
            @error('command') <p class="ui-error">{{ $message }}</p> @enderror
            <x-slot:footer>
                <button type="button" wire:click="closeModal" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="wipe" class="btn btn-danger-solid" wire:loading.attr="disabled" wire:target="wipe">Wipe phone</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
