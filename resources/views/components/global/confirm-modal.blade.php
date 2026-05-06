<div
    x-data="{
        open: false,
        title: 'Confirm action',
        message: 'Are you sure you want to continue?',
        confirmLabel: 'Confirm',
        cancelLabel: 'Cancel',
        variant: 'danger',
        action: null,
        ask(event) {
            const detail = event.detail || {};
            this.title = detail.title || this.title;
            this.message = detail.message || this.message;
            this.confirmLabel = detail.confirmLabel || this.confirmLabel;
            this.cancelLabel = detail.cancelLabel || this.cancelLabel;
            this.variant = detail.variant || 'danger';
            this.action = detail.action || null;
            this.open = true;
        },
        confirm() {
            const action = this.action;
            this.open = false;
            this.action = null;

            if (typeof action === 'function') {
                action();
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
                <div class="confirm-actions">
                    <button type="button" class="btn" x-on:click="open = false" x-text="cancelLabel"></button>
                    <button type="button" class="btn" :class="variant === 'danger' ? 'btn-danger' : 'btn-primary'" x-on:click="confirm()" x-text="confirmLabel"></button>
                </div>
            </div>
        </div>
    </div>
</div>
