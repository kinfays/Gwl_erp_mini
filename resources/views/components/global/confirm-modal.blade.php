<div
    x-data="{
        open: false,
        title: 'Confirm action',
        message: 'Are you sure you want to continue?',
        confirmLabel: 'Confirm',
        cancelLabel: 'Cancel',
        variant: 'danger',
        action: null,
        input: null,
        inputValue: '',
        inputError: '',
        ask(event) {
            const detail = event.detail || {};
            this.title = detail.title || this.title;
            this.message = detail.message || this.message;
            this.confirmLabel = detail.confirmLabel || this.confirmLabel;
            this.cancelLabel = detail.cancelLabel || this.cancelLabel;
            this.variant = detail.variant || 'danger';
            this.action = detail.action || null;
            this.input = detail.input || null;
            this.inputValue = detail.input?.value || '';
            this.inputError = '';
            this.open = true;
        },
        confirm() {
            if (this.input?.required && !this.inputValue) {
                this.inputError = this.input.error || 'This field is required.';
                return;
            }

            const action = this.action;
            const inputValue = this.inputValue;
            this.open = false;
            this.action = null;
            this.input = null;
            this.inputValue = '';
            this.inputError = '';

            if (typeof action === 'function') {
                action(inputValue);
            }
        }
    }"
    x-on:confirm-action.window="ask($event)"
    x-on:keydown.escape.window="open = false"
    x-cloak
>
    <div class="confirm-backdrop" x-show="open" x-transition.opacity>
        <div class="confirm-card" x-show="open" x-transition>
            <div class="confirm-icon" :class="`confirm-${variant}`">!</div>
            <div class="confirm-body">
                <h2 x-text="title"></h2>
                <p x-text="message"></p>
                <div x-show="input" style="margin-top:12px;text-align:left">
                    <label class="form-label" x-text="input?.label || 'Value'"></label>
                    <select x-model="inputValue" class="form-input">
                        <option value="" x-text="input?.placeholder || 'Select an option'"></option>
                        <template x-for="option in (input?.options || [])" :key="option.value">
                            <option :value="option.value" x-text="option.label"></option>
                        </template>
                    </select>
                    <div x-show="inputError" class="form-label form-error" x-text="inputError"></div>
                </div>
                <div class="confirm-actions">
                    <button type="button" class="btn" x-on:click="open = false" x-text="cancelLabel"></button>
                    <button type="button" class="btn" :class="variant === 'danger' ? 'btn-danger' : 'btn-primary'" x-on:click="confirm()" x-text="confirmLabel"></button>
                </div>
            </div>
        </div>
    </div>
</div>
