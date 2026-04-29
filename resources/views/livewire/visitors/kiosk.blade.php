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
                <div class="vk-success" x-data="{ count: 5 }" x-init="setInterval(() => { count--; if (count <= 0) $wire.resetKiosk() }, 1000)">
                    <div class="vk-success-mark">✓</div>
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
                    <div class="vk-warning">A visitor with this name is already checked in today.</div>
                @endif

                @if ($step === 1)
                    <h1>Name & Phone</h1>
                    <label class="vk-label">Full Name</label>
                    <input type="text" wire:model.live.debounce.500ms="visitor_name" wire:blur="checkDuplicate" class="vk-input" autocomplete="off" autofocus>
                    @error('visitor_name') <div class="vk-error">{{ $message }}</div> @enderror

                    <label class="vk-label">Phone Number</label>
                    <input type="tel" wire:model="phone" class="vk-input" autocomplete="off">
                @elseif ($step === 2)
                    <h1>Who are you visiting?</h1>
                    <label class="vk-label">Search employee</label>
                    <input type="text" wire:model.live.debounce.400ms="employeeSearch" class="vk-input" placeholder="Type at least 2 characters">
                    @error('staff_id') <div class="vk-error">{{ $message }}</div> @enderror

                    <div class="vk-results">
                        @if ($selectedEmployee)
                            <div class="vk-result selected">
                                <strong>{{ $selectedEmployee->full_name }}</strong>
                                <span>{{ $selectedEmployee->department?->department_name ?? 'No department' }} · {{ $selectedEmployee->district?->district_name ?? 'Location not assigned' }}</span>
                            </div>
                        @endif

                        @forelse ($employees as $employee)
                            <button type="button" wire:click="$set('staff_id', {{ $employee->id }})" class="vk-result">
                                <strong>{{ $employee->full_name }}</strong>
                                <span>{{ $employee->department?->department_name ?? 'No department' }} · {{ $employee->district?->district_name ?? 'Location not assigned' }}</span>
                            </button>
                        @empty
                            <div class="vk-muted">Search results will appear here.</div>
                        @endforelse
                    </div>
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
                        <button type="button" wire:click="submit" class="vk-btn">Submit</button>
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
        <div class="vk-checkout-form">
            <input type="text" wire:model="selfCheckoutCode" class="vk-code-input" maxlength="3" placeholder="Code">
            <button type="button" wire:click="findSelfCheckout" class="vk-btn small">Find</button>
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
                    <button type="button" wire:click="confirmSelfCheckout" class="vk-btn small">Confirm Checkout</button>
                </div>
            </div>
        @endif
    </div>
</div>
