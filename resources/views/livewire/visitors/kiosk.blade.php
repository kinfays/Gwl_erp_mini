<div class="visitor-kiosk">
    @php
        $steps = [1 => 'Your details', 2 => 'Who you are visiting', 3 => 'Purpose', 4 => 'Signature'];
    @endphp

    <header class="vk-top">
        <div>
            <div class="vk-brand">GWL Visitor Kiosk</div>
            <div class="vk-clock" data-kiosk-clock>{{ now()->format('l, F d, Y h:i A') }}</div>
        </div>

        @unless ($success)
            <ol class="vk-steps" aria-label="Check-in progress">
                @foreach ($steps as $number => $stepLabel)
                    <li @class(['is-done' => $number < $step]) @if ($number === $step) aria-current="step" @endif>
                        <span class="vk-step-num" aria-hidden="true">
                            @if ($number < $step)
                                <x-ui.icon name="check" />
                            @else
                                {{ $number }}
                            @endif
                        </span>
                        <span class="vk-step-label">
                            <span class="sr-only-text">Step {{ $number }} of 4: </span>{{ $stepLabel }}@if ($number < $step)<span class="sr-only-text"> (done)</span>@endif
                        </span>
                    </li>
                @endforeach
            </ol>
        @endunless
    </header>

    <main class="vk-main">
        <div class="vk-card">
            @if ($success)
                <div
                    class="vk-success"
                    x-data="{
                        codeSeconds: 20,
                        resetSeconds: {{ max(1, (int) config('gwl.visitor_kiosk_reset_seconds', 5)) }},
                        phase: 'code',
                        remaining: 20,
                        timer: null,
                        init() {
                            // The checkout code stays up (and the kiosk does not reset) until the
                            // visitor taps Done or the code window runs out; then the kiosk resets.
                            this.remaining = this.codeSeconds;
                            this.countdown(() => this.done());
                        },
                        done() {
                            if (this.phase !== 'code') return;
                            this.phase = 'closing';
                            this.remaining = this.resetSeconds;
                            this.countdown(() => $wire.resetKiosk());
                        },
                        countdown(onZero) {
                            this.stop();
                            this.timer = setInterval(() => {
                                this.remaining -= 1;

                                if (this.remaining <= 0) {
                                    this.stop();
                                    onZero();
                                }
                            }, 1000);
                        },
                        stop() {
                            if (this.timer !== null) {
                                clearInterval(this.timer);
                                this.timer = null;
                            }
                        },
                        destroy() {
                            this.stop();
                        }
                    }"
                >
                    <div class="vk-success-mark" aria-hidden="true"><x-ui.icon name="check" /></div>
                    <div role="status">
                        <h1>Welcome {{ $successName }}!</h1>
                        <p>Your visit has been recorded. Please proceed to reception.</p>
                    </div>

                    <div class="vk-code-panel" x-show="phase === 'code'">
                        <span class="vk-code-label">Your checkout code</span>
                        <strong class="vk-code-value">{{ $checkoutCode }}</strong>
                        <span class="vk-code-hint">Note it down. You will need it to check out when you leave.</span>
                        <button type="button" class="vk-btn" x-on:click="done()">
                            Done, I have my code
                        </button>
                        <small class="vk-code-timer" aria-hidden="true">Shown for <span x-text="remaining"></span> more seconds</small>
                    </div>

                    <div class="vk-closing" x-show="phase === 'closing'" x-cloak>
                        <p>Thank you. This screen resets in <span x-text="remaining"></span>s.</p>
                        <button type="button" class="vk-btn light" x-on:click="stop(); $wire.resetKiosk()">Start a new check-in</button>
                    </div>
                </div>
            @else
                @if ($duplicateWarning)
                    <div class="vk-warning" role="alert">
                        <x-ui.icon name="triangle-alert" />
                        <span>A visitor with this phone number is already checked in today.</span>
                    </div>
                @endif

                @if ($step === 1)
                    <h1>Name &amp; Phone</h1>
                    <label class="vk-label" for="vk-visitor-name">Full Name</label>
                    <input id="vk-visitor-name" type="text" wire:model.live.debounce.500ms="visitor_name" wire:blur="checkDuplicate" class="vk-input" autocomplete="off" autofocus @error('visitor_name') aria-invalid="true" aria-describedby="vk-visitor-name-error" @enderror>
                    @error('visitor_name') <div class="vk-error" id="vk-visitor-name-error" role="alert"><x-ui.icon name="circle-alert" /><span>{{ $message }}</span></div> @enderror

                    <label class="vk-label" for="vk-phone">Phone Number</label>
                    <input id="vk-phone" type="tel" wire:model.live.debounce.500ms="phone" class="vk-input" autocomplete="off" inputmode="tel" @error('phone') aria-invalid="true" aria-describedby="vk-phone-error" @enderror>
                    @error('phone') <div class="vk-error" id="vk-phone-error" role="alert"><x-ui.icon name="circle-alert" /><span>{{ $message }}</span></div> @enderror
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
                    <label class="vk-label" for="vk-purpose">Purpose of visit <span class="vk-optional">(optional)</span></label>
                    <textarea id="vk-purpose" wire:model="purpose" class="vk-textarea" rows="6" placeholder="Optional"></textarea>
                @elseif ($step === 4)
                    <h1>Signature</h1>
                    <p class="vk-label" id="vk-signature-label">Sign below with your finger</p>
                    <div wire:ignore class="vk-signature-wrap">
                        <canvas data-signature-pad="signature" class="vk-signature" role="img" aria-labelledby="vk-signature-label"></canvas>
                    </div>
                    <div class="vk-actions left">
                        <button type="button" data-clear-signature="signature" class="vk-btn light small">
                            <x-ui.icon name="undo-2" />
                            Clear
                        </button>
                    </div>
                    @error('signature') <div class="vk-error" role="alert"><x-ui.icon name="circle-alert" /><span>Signature is required.</span></div> @enderror
                @endif

                <div class="vk-actions">
                    @if ($step > 1)
                        <button type="button" wire:click="back" class="vk-btn light">
                            <x-ui.icon name="arrow-left" />
                            Back
                        </button>
                    @endif

                    @if ($step < 4)
                        <button type="button" wire:click="next" class="vk-btn">
                            Next
                            <x-ui.icon name="arrow-right" />
                        </button>
                    @else
                        <button
                            type="button"
                            class="vk-btn"
                            x-data
                            x-on:click.prevent="(async () => { await window.syncKioskSignature?.('signature'); $wire.submit(); })()"
                            wire:loading.attr="disabled"
                            wire:target="submit"
                        >
                            <x-ui.icon name="check" />
                            Submit
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </main>

    <section class="vk-checkout" aria-labelledby="vk-checkout-title">
        <div>
            <h2 id="vk-checkout-title">Checking out?</h2>
            <p>Enter your code to logout.</p>
        </div>
        <div class="vk-checkout-form" x-data="{ code: @entangle('selfCheckoutCode').live }">
            <label for="vk-checkout-code" class="sr-only-text">Checkout code</label>
            <input id="vk-checkout-code" type="text" x-model="code" class="vk-code-input" maxlength="3" inputmode="numeric" autocomplete="off" placeholder="Code">
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
            <div class="vk-checkout-msg" role="status">{{ $selfCheckoutMessage }}</div>
        @endif

        @if ($selfCheckoutVisitor)
            <div class="vk-confirm">
                <strong id="vk-self-checkout-title">Is this you, {{ $selfCheckoutVisitor->visitor_name }}?</strong>
                <p class="vk-muted" id="vk-mini-signature-label">Sign below to confirm your checkout.</p>
                <div wire:ignore class="vk-mini-signature-wrap">
                    <canvas data-signature-pad="selfCheckoutSignature" class="vk-mini-signature" role="img" aria-labelledby="vk-mini-signature-label"></canvas>
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
    </section>
</div>
