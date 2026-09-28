@php
    $initialToasts = collect([
        session('success') ? ['type' => 'success', 'message' => session('success')] : null,
        session('status') ? ['type' => 'success', 'message' => session('status')] : null,
        session('error') ? ['type' => 'error', 'message' => session('error')] : null,
        session('warning') ? ['type' => 'warning', 'message' => session('warning')] : null,
        session('info') ? ['type' => 'info', 'message' => session('info')] : null,
        $errors->any() ? ['type' => 'error', 'message' => $errors->first()] : null,
    ])->filter()->values();
@endphp

<div
    class="toast-stack"
    role="region"
    aria-label="{{ __('Notifications') }}"
    x-data="{
        nextId: 1,
        toasts: [],
        timers: {},
        push(detail) {
            const toast = {
                id: this.nextId++,
                type: detail.type || 'info',
                message: detail.message || detail.detail?.message || 'Done.'
            };

            const show = () => {
                this.toasts = [...this.toasts.slice(-2), toast];
                this.schedule(toast.id);
            };

            // An open drawer or modal (x-trap.inert) hides the rest of the page from assistive
            // tech. Un-hide this live region first, then add the message a beat later so it is
            // still announced.
            if (this.$root.getAttribute('aria-hidden') === 'true') {
                this.$root.removeAttribute('aria-hidden');
                setTimeout(show, 100);

                return;
            }

            show();
        },
        schedule(id) {
            clearTimeout(this.timers[id]);
            this.timers[id] = setTimeout(() => this.remove(id), 6000);
        },
        pause(id) {
            clearTimeout(this.timers[id]);
        },
        remove(id) {
            clearTimeout(this.timers[id]);
            delete this.timers[id];
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        }
    }"
    x-init="@js($initialToasts).forEach((toast) => push(toast))"
    x-on:toast.window="push($event.detail || {})"
    aria-live="polite"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            class="toast"
            :class="`toast-${toast.type}`"
            x-on:mouseenter="pause(toast.id)"
            x-on:mouseleave="schedule(toast.id)"
            x-on:focusin="pause(toast.id)"
            x-on:focusout="schedule(toast.id)"
            x-transition:enter="toast-enter"
            x-transition:enter-start="toast-enter-start"
            x-transition:enter-end="toast-enter-end"
            x-transition:leave="toast-leave"
            x-transition:leave-start="toast-leave-start"
            x-transition:leave-end="toast-leave-end"
        >
            <span class="toast-icon" aria-hidden="true">
                <x-ui.icon name="circle-check" x-show="toast.type === 'success'" />
                <x-ui.icon name="circle-alert" x-show="toast.type === 'error'" />
                <x-ui.icon name="triangle-alert" x-show="toast.type === 'warning'" />
                <x-ui.icon name="info" x-show="! ['success', 'error', 'warning'].includes(toast.type)" />
            </span>
            <span class="toast-message" x-text="toast.message"></span>
            <button type="button" class="toast-close" x-on:click="remove(toast.id)" aria-label="{{ __('Dismiss notification') }}">
                <x-ui.icon name="x" class="icon-sm" />
            </button>
        </div>
    </template>
</div>

@once
    <audio id="gwl-notification-sound" src="{{ asset('sound/waterdrop.mp3') }}" preload="auto"></audio>

    <script>
        (() => {
            if (window.gwlNotificationSoundReady) {
                return;
            }

            window.gwlNotificationSoundReady = true;

            let lastPlayedAt = 0;

            window.addEventListener('notification-sound', () => {
                const now = Date.now();

                if (now - lastPlayedAt < 900) {
                    return;
                }

                const audio = document.getElementById('gwl-notification-sound');

                if (! audio) {
                    return;
                }

                lastPlayedAt = now;

                try {
                    audio.currentTime = 0;
                } catch (error) {
                    // Browsers can reject seeking before metadata is ready.
                }

                const playPromise = audio.play();

                if (playPromise && typeof playPromise.catch === 'function') {
                    playPromise.catch(() => {});
                }
            });
        })();
    </script>
@endonce
