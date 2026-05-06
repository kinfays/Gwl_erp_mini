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

        <x-global.toast-center />
        <x-global.confirm-modal />

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

            function signatureComponent(canvas) {
                const root = canvas.closest('[wire\\:id]');

                return root && window.Livewire ? Livewire.find(root.getAttribute('wire:id')) : null;
            }

            function signatureValue(canvas) {
                const pad = canvas._kioskSignaturePad;

                if (pad && typeof pad.isEmpty === 'function' && pad.isEmpty()) {
                    return '';
                }

                if (!pad && canvas.dataset.drawn !== '1') {
                    return '';
                }

                return canvas.toDataURL('image/png');
            }

            function setSignatureValue(canvas, value, live = false) {
                const component = signatureComponent(canvas);

                if (!component || typeof component.set !== 'function') {
                    return Promise.resolve();
                }

                const result = component.set(canvas.dataset.signaturePad, value, live);

                return result && typeof result.then === 'function'
                    ? result
                    : Promise.resolve(result);
            }

            function syncSignatureCanvas(canvas, live = false) {
                return setSignatureValue(canvas, signatureValue(canvas), live);
            }

            window.syncKioskSignature = function (property) {
                initSignaturePads();

                const canvas = document.querySelector(`[data-signature-pad="${property}"]`);

                return canvas ? syncSignatureCanvas(canvas, true) : Promise.resolve();
            };

            window.clearKioskSignature = function (property) {
                initSignaturePads();

                const canvas = document.querySelector(`[data-signature-pad="${property}"]`);

                if (!canvas) {
                    return Promise.resolve();
                }

                if (canvas._kioskSignaturePad) {
                    canvas._kioskSignaturePad.clear();
                } else {
                    canvas.getContext('2d')?.clearRect(0, 0, canvas.width, canvas.height);
                }

                canvas.dataset.drawn = '0';

                return setSignatureValue(canvas, '', true);
            };

            function initSignaturePads() {
                document.querySelectorAll('[data-signature-pad]').forEach((canvas) => {
                    if (canvas.dataset.ready === '1') return;

                    const context = canvas.getContext('2d');
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    const rect = canvas.getBoundingClientRect();
                    const width = rect.width || canvas.offsetWidth || 300;
                    const height = rect.height || canvas.offsetHeight || 150;
                    canvas.width = width * ratio;
                    canvas.height = height * ratio;
                    context.scale(ratio, ratio);
                    canvas.dataset.drawn = '0';

                    let pad;
                    if (window.SignaturePad) {
                        pad = new SignaturePad(canvas, {
                            backgroundColor: 'rgb(255,255,255)',
                            penColor: 'rgb(23,34,52)'
                        });

                        if (typeof pad.addEventListener === 'function') {
                            pad.addEventListener('endStroke', () => syncSignatureCanvas(canvas));
                        }
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
                            canvas.dataset.drawn = '1';
                            syncSignatureCanvas(canvas);
                        }

                        canvas.addEventListener('mousedown', start);
                        canvas.addEventListener('mousemove', move);
                        canvas.addEventListener('mouseup', stop);
                        canvas.addEventListener('mouseleave', stop);
                        canvas.addEventListener('touchstart', start, { passive: false });
                        canvas.addEventListener('touchmove', move, { passive: false });
                        canvas.addEventListener('touchend', stop);
                    }

                    canvas._kioskSignaturePad = pad || null;

                    ['pointerup', 'mouseup', 'touchend'].forEach((eventName) => {
                        canvas.addEventListener(eventName, () => {
                            requestAnimationFrame(() => syncSignatureCanvas(canvas));
                        });
                    });

                    canvas.dataset.ready = '1';
                });
            }

            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-clear-signature]');

                if (!button) return;

                event.preventDefault();
                window.clearKioskSignature(button.dataset.clearSignature);
            });

            document.addEventListener('kiosk-clear-signature', (event) => {
                window.clearKioskSignature(event.detail?.property);
            });

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
                    Livewire.hook('commit', ({ succeed }) => succeed(() => initSignaturePads()));
                }
            });
        </script>
    </body>
</html>
