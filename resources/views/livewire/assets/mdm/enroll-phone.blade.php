<div>
    <x-ui.page-header title="Enroll a Phone" description="Company-owned, fully managed. Generate a one-time QR code for one phone and scan it at the setup wizard of a factory-reset device." />

    @if ($qrSvg)
        <div class="ui-grid ui-grid-2 dash-row">
            <x-ui.card title="Scan this code" :description="'For '.$qrAssetLabel">
                <div class="mdm-qr" role="img" aria-label="Enrollment QR code for {{ $qrAssetLabel }}">{!! $qrSvg !!}</div>

                <p class="mdm-qr-expiry" x-data="{ remaining: '' }" x-init="
                    const end = new Date('{{ $expiresAtIso }}').getTime();
                    const tick = () => {
                        const ms = end - Date.now();
                        remaining = ms <= 0 ? 'expired' : Math.ceil(ms / 60000) + ' min left';
                    };
                    tick(); setInterval(tick, 15000);
                ">
                    <x-ui.icon name="clock" class="icon-sm" />
                    Valid until <strong>{{ $expiresAtLabel }}</strong>
                    <span class="ui-hint" x-text="'(' + remaining + ')'"></span>
                </p>
                <p class="ui-hint">One-time use. Anyone who scans it can enroll a phone against {{ $qrAssetLabel }}, so keep it on your screen only.</p>

                <div class="ui-actions-row">
                    <button type="button" wire:click="newCode" class="btn btn-secondary">Done — enroll another phone</button>
                </div>
            </x-ui.card>

            <x-ui.card title="Rollout steps">
                <x-assets.mdm-steps />
            </x-ui.card>
        </div>
    @else
        <div class="ui-grid ui-grid-2 dash-row">
            <x-ui.card title="Choose the phone and policy">
                @if ($assets->isEmpty())
                    <x-ui.alert tone="info">
                        No phone is ready to enroll. A phone must be an active handset (type “Phone”) in your region, have a serial number or IMEI on its record, and not already be enrolled.
                    </x-ui.alert>
                @endif

                <div class="ui-form-grid">
                    <div class="span-2">
                        <x-ui.select label="Phone" wire:model="assetId" required>
                            <option value="">Select a phone</option>
                            @foreach ($assets as $asset)
                                <option value="{{ $asset->id }}">
                                    {{ $asset->asset_name }} — {{ $asset->serial_number ?: 'IMEI '.$asset->imei }}
                                    @if ($asset->assignedTo) ({{ $asset->assignedTo->full_name }}) @endif
                                </option>
                            @endforeach
                        </x-ui.select>
                    </div>
                    <div class="span-2">
                        <x-ui.select label="Policy" wire:model="policyId" required hint="Only policies published to Google are listed.">
                            <option value="">Select a policy</option>
                            @foreach ($policies as $policy)
                                <option value="{{ $policy->id }}">{{ $policy->name }} (v{{ $policy->version }})</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </div>

                @error('asset') <p class="ui-error">{{ $message }}</p> @enderror

                <x-slot:footer>
                    <button type="button" wire:click="generate" class="btn btn-primary" wire:loading.attr="disabled" wire:target="generate">
                        <x-ui.icon name="smartphone" />
                        Generate QR code
                    </button>
                    <span class="ui-hint">The code is valid for {{ $tokenMinutes }} minutes.</span>
                </x-slot:footer>
            </x-ui.card>

            <x-ui.card title="Rollout steps">
                <x-assets.mdm-steps />
            </x-ui.card>
        </div>
    @endif
</div>
