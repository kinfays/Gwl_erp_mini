<div class="visitor-kiosk">
    <div class="vk-top">
        <div>
            <div class="vk-brand">GWL Visitor Kiosk</div>
            <div class="vk-clock" data-kiosk-clock>{{ now()->format('l, F d, Y h:i A') }}</div>
        </div>
        <div class="vk-step">Step {{ $step }} of 4</div>
    </div>

    <div class="vk-main">
        <div class="vk-card">
            @if ($success)
                <div class="vk-success" x-data="{ count: {{ (int) config('gwl.visitor_kiosk_reset_seconds', 5) }} }" x-init="setInterval(() => { count--; if (count <= 0) $wire.resetKiosk() }, 1000)">
                    <div class="vk-success-mark">OK</div>
                    <h1>Welcome {{ $successName }}!</h1>
                    <p>Your visit has been recorded. Please proceed to reception.</p>
                    <div class="vk-code" x-data="{ show: true, count: 30 }" x-init="setInterval(() => { if (count > 0) count-- }, 1000)" x-show="show">
                        <span>Your checkout code</span>
                        <strong>{{ $checkoutCode }}</strong>
                        <small>Visible for <span x-text="count"></span>s</small>
                        <button type="button" x-on:click="show = false">Dismiss</button>
                    </div>
                    <p class="vk-reset">Resetting in <span x-text="count"></span>s</p>
                </div>
            @else
                @if ($duplicateWarning)
                    <div class="vk-warning">A visitor with this phone number is already checked in today.</div>
                @endif

                @if ($step === 1)
                    <h1>Name & Phone</h1>
                    <label class="vk-label">Full Name</label>
                    <input type="text" wire:model.live.debounce.500ms="visitor_name" wire:blur="checkDuplicate" class="vk-input" autocomplete="off" autofocus>
                    @error('visitor_name') <div class="vk-error">{{ $message }}</div> @enderror

                    <label class="vk-label">Phone Number</label>
                    <input type="tel" wire:model.live.debounce.500ms="phone" class="vk-input" autocomplete="off">
                    @error('phone') <div class="vk-error">{{ $message }}</div> @enderror
                @elseif ($step === 2)
                    <h1>Who are you visiting?</h1>
                    <x-form.combobox
                        class="vk-combobox"
                        label="Search employee"
                        model="staff_id"
                        :options="$employeeOptions"
                        placeholder="Type a name, staff ID, department, or location"
                        empty-text="No matching employees"
                    />
                @elseif ($step === 3)
                    <h1>Purpose</h1>
                    <label class="vk-label">Purpose of visit</label>
                    <textarea wire:model="purpose" class="vk-textarea" rows="7" placeholder="Optional"></textarea>
                @elseif ($step === 4)
                    <h1>Signature</h1>
                    <label class="vk-label">Sign below</label>
                    <div wire:ignore class="vk-signature-wrap">
                        <canvas data-signature-pad="signature" class="vk-signature"></canvas>
                    </div>
                    <div class="vk-actions left">
                        <button type="button" data-clear-signature="signature" class="vk-btn light">Clear</button>
                    </div>
                    @error('signature') <div class="vk-error">Signature is required.</div> @enderror
                @endif

                <div class="vk-actions">
                    @if ($step > 1)
                        <button type="button" wire:click="back" class="vk-btn light">Back</button>
                    @endif

                    @if ($step < 4)
                        <button type="button" wire:click="next" class="vk-btn">Next</button>
                    @else
                        <button
                            type="button"
                            class="vk-btn"
                            x-data
                            x-on:click.prevent="(async () => { await window.syncKioskSignature?.('signature'); $wire.submit(); })()"
                            wire:loading.attr="disabled"
                            wire:target="submit"
                        >
                            Submit
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="vk-checkout">
        <div>
            <h2>Checking out?</h2>
            <p>Enter your code to logout.</p>
        </div>
        <div class="vk-checkout-form" x-data="{ code: @entangle('selfCheckoutCode').live }">
            <input type="text" x-model="code" class="vk-code-input" maxlength="3" inputmode="numeric" autocomplete="off" placeholder="Code">
            <button
                type="button"
                wire:click="findSelfCheckout"
                x-bind:disabled="code.trim() === ''"
                class="vk-btn small"
            >
                Find
            </button>
            <button type="button" wire:click="cancelSelfCheckout" x-show="code.trim() !== ''" x-cloak class="vk-btn light small">Cancel</button>
        </div>

        @if ($selfCheckoutMessage)
            <div class="vk-checkout-msg">{{ $selfCheckoutMessage }}</div>
        @endif

        @if ($selfCheckoutVisitor)
            <div class="vk-confirm">
                <strong>Is this you, {{ $selfCheckoutVisitor->visitor_name }}?</strong>
                <div wire:ignore class="vk-mini-signature-wrap">
                    <canvas data-signature-pad="selfCheckoutSignature" class="vk-mini-signature"></canvas>
                </div>
                <div class="vk-actions left">
                    <button type="button" data-clear-signature="selfCheckoutSignature" class="vk-btn light small">Clear</button>
                    <button type="button" wire:click="cancelSelfCheckout" class="vk-btn light small">Cancel</button>
                    <button
                        type="button"
                        class="vk-btn small"
                        x-data
                        x-on:click.prevent="$dispatch('confirm-action', {
                            title: 'Confirm checkout?',
                            message: 'Your visit will be closed using the signature shown here.',
                            confirmLabel: 'Confirm Checkout',
                            variant: 'primary',
                            action: async () => {
                                await window.syncKioskSignature?.('selfCheckoutSignature');
                                await $wire.confirmSelfCheckout();
                            }
                        })"
                    >
                        Confirm Checkout
                    </button>
                </div>
            </div>
        @endif
    </div>
</div>
