<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Visitor Kiosk</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="visitor-kiosk-body">
        <livewire:visitors.kiosk />

        @livewireScripts
        <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.2.0/dist/signature_pad.umd.min.js"></script>
        <script>
            function kioskClock() {
                const target = document.querySelector('[data-kiosk-clock]');
                if (!target) return;
                const now = new Date();
                target.textContent = now.toLocaleString([], {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            function initSignaturePads() {
                document.querySelectorAll('[data-signature-pad]').forEach((canvas) => {
                    if (canvas.dataset.ready === '1') return;

                    const property = canvas.dataset.signaturePad;
                    const clearButton = document.querySelector(`[data-clear-signature="${property}"]`);
                    const context = canvas.getContext('2d');
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    const rect = canvas.getBoundingClientRect();
                    canvas.width = rect.width * ratio;
                    canvas.height = rect.height * ratio;
                    context.scale(ratio, ratio);

                    let pad;
                    if (window.SignaturePad) {
                        pad = new SignaturePad(canvas, {
                            backgroundColor: 'rgb(255,255,255)',
                            penColor: 'rgb(23,34,52)'
                        });
                    } else {
                        let drawing = false;

                        function point(event) {
                            const source = event.touches ? event.touches[0] : event;
                            const box = canvas.getBoundingClientRect();
                            return { x: source.clientX - box.left, y: source.clientY - box.top };
                        }

                        function start(event) {
                            drawing = true;
                            const p = point(event);
                            context.beginPath();
                            context.moveTo(p.x, p.y);
                            event.preventDefault();
                        }

                        function move(event) {
                            if (!drawing) return;
                            const p = point(event);
                            context.lineWidth = 2;
                            context.lineCap = 'round';
                            context.strokeStyle = '#172234';
                            context.lineTo(p.x, p.y);
                            context.stroke();
                            event.preventDefault();
                        }

                        function stop() {
                            if (!drawing) return;
                            drawing = false;
                            syncSignature();
                        }

                        canvas.addEventListener('mousedown', start);
                        canvas.addEventListener('mousemove', move);
                        canvas.addEventListener('mouseup', stop);
                        canvas.addEventListener('mouseleave', stop);
                        canvas.addEventListener('touchstart', start, { passive: false });
                        canvas.addEventListener('touchmove', move, { passive: false });
                        canvas.addEventListener('touchend', stop);
                    }

                    function component() {
                        const root = canvas.closest('[wire\\:id]');
                        return root ? Livewire.find(root.getAttribute('wire:id')) : null;
                    }

                    function syncSignature() {
                        if (!pad || !pad.isEmpty()) {
                            component()?.set(property, canvas.toDataURL('image/png'));
                        }
                    }

                    canvas.addEventListener('mouseup', syncSignature);
                    canvas.addEventListener('touchend', syncSignature);

                    clearButton?.addEventListener('click', () => {
                        if (pad) {
                            pad.clear();
                        } else {
                            context.clearRect(0, 0, canvas.width, canvas.height);
                        }
                        component()?.set(property, '');
                    });

                    canvas.dataset.ready = '1';
                });
            }

            document.addEventListener('DOMContentLoaded', () => {
                kioskClock();
                initSignaturePads();
                setInterval(kioskClock, 60000);
            });
            document.addEventListener('livewire:navigated', initSignaturePads);
            document.addEventListener('livewire:update', initSignaturePads);
            document.addEventListener('livewire:init', () => {
                if (window.Livewire?.hook) {
                    Livewire.hook('morph.updated', initSignaturePads);
                    Livewire.hook('commit', ({ succeed }) => succeed(initSignaturePads));
                }
            });
        </script>
    </body>
</html>
