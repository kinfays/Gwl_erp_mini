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
    x-data="{
        nextId: 1,
        toasts: [],
        push(detail) {
            const toast = {
                id: this.nextId++,
                type: detail.type || 'info',
                message: detail.message || detail.detail?.message || 'Done.'
            };

            this.toasts = [...this.toasts.slice(-2), toast];
            setTimeout(() => this.remove(toast.id), 4000);
        },
        remove(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        }
    }"
    x-init="@js($initialToasts).forEach((toast) => push(toast))"
    x-on:toast.window="push($event.detail || {})"
    aria-live="polite"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div class="toast" :class="`toast-${toast.type}`" x-transition>
            <span class="toast-icon" x-text="toast.type === 'success' ? 'OK' : toast.type === 'error' ? '!' : toast.type === 'warning' ? '?' : 'i'"></span>
            <span class="toast-message" x-text="toast.message"></span>
            <button type="button" x-on:click="remove(toast.id)" aria-label="Close notification">&times;</button>
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
