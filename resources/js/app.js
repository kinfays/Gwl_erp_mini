import './bootstrap';

import Alpine from 'alpinejs';

const livewirePresent = window.Livewire
    || document.querySelector('[wire\\:id], script[src*="livewire"]');

if (! livewirePresent && ! window.Alpine) {
    window.Alpine = Alpine;

    Alpine.start();
}
