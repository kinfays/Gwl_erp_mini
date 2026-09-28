import './bootstrap';

// Atkinson Hyperlegible (OFL), self-hosted from npm so an intranet install never depends on a font CDN.
// Imported here rather than from app.css so Vite resolves the packages' relative font URLs.
// Next carries the whole UI; Mono is only for identifiers (staff IDs, plates, serials, codes).
import '@fontsource-variable/atkinson-hyperlegible-next';
import '@fontsource-variable/atkinson-hyperlegible-next/wght-italic.css';
import '@fontsource-variable/atkinson-hyperlegible-mono';

import Alpine from 'alpinejs';

const THEME_KEY = 'gwl-theme';
const SIDEBAR_KEY = 'gwl-sidebar';

function storedTheme() {
    try {
        return localStorage.getItem(THEME_KEY);
    } catch (error) {
        return null;
    }
}

// Shared UI state for every layout. Livewire starts its bundled Alpine on DOMContentLoaded, after this
// deferred module has run, so these registrations are in place for both Livewire and plain pages.
document.addEventListener('alpine:init', () => {
    const alpine = window.Alpine;

    alpine.store('theme', {
        dark: document.documentElement.classList.contains('dark'),

        init() {
            // Follow the OS setting until the user picks a theme, and keep open tabs in step.
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
                if (! storedTheme()) {
                    this.apply(event.matches);
                }
            });

            window.addEventListener('storage', (event) => {
                if (event.key === THEME_KEY && event.newValue) {
                    this.apply(event.newValue === 'dark');
                }
            });
        },

        toggle() {
            this.apply(! this.dark);

            try {
                localStorage.setItem(THEME_KEY, this.dark ? 'dark' : 'light');
            } catch (error) {
                // Private windows can refuse storage; the theme still applies for this page.
            }
        },

        apply(dark) {
            const root = document.documentElement;

            // Switch every colour in the same frame instead of letting hover transitions lag behind.
            root.classList.add('theme-switching');
            this.dark = dark;
            root.classList.toggle('dark', dark);
            root.style.colorScheme = dark ? 'dark' : 'light';
            window.getComputedStyle(root).getPropertyValue('color');
            window.setTimeout(() => root.classList.remove('theme-switching'), 0);

            window.dispatchEvent(new CustomEvent('gwl:theme-changed', { detail: { dark } }));
        },
    });

    // App shell: off-canvas drawer below 1024px, collapsible icon rail above it.
    alpine.data('erpShell', () => ({
        drawer: false,
        collapsed: document.documentElement.dataset.sidebar === 'collapsed',

        openDrawer() {
            this.drawer = true;
        },

        closeDrawer() {
            this.drawer = false;
        },

        toggleCollapsed() {
            this.collapsed = ! this.collapsed;
            document.documentElement.dataset.sidebar = this.collapsed ? 'collapsed' : 'expanded';

            try {
                localStorage.setItem(SIDEBAR_KEY, this.collapsed ? 'collapsed' : 'expanded');
            } catch (error) {
                // Non-essential preference.
            }
        },
    }));

    // "Jump to a page" (Ctrl/Cmd + K): client-side filter over the pages ErpNavigation already built.
    // Options are rendered server-side (so they can carry icons); this component only filters and
    // tracks the highlighted option. `results` holds the indexes of the options that match.
    alpine.data('jumpTo', (items = []) => ({
        open: false,
        query: '',
        active: 0,
        items,

        get results() {
            const terms = this.query.toLowerCase().trim().split(/\s+/).filter(Boolean);

            return this.items.reduce((matches, item, index) => {
                const haystack = `${item.label} ${item.group || ''}`.toLowerCase();

                if (terms.every((term) => haystack.includes(term))) {
                    matches.push(index);
                }

                return matches;
            }, []);
        },

        get activeId() {
            const index = this.results[this.active];

            return index === undefined ? null : `jump-option-${index}`;
        },

        isVisible(index) {
            return this.results.includes(index);
        },

        isActive(index) {
            return this.results[this.active] === index;
        },

        hover(index) {
            const position = this.results.indexOf(index);

            if (position !== -1) {
                this.active = position;
            }
        },

        show() {
            this.query = '';
            this.active = 0;
            this.open = true;
            this.$nextTick(() => this.$refs.input?.focus());
        },

        hide() {
            this.open = false;
        },

        move(step) {
            const count = this.results.length;

            if (count === 0) {
                return;
            }

            this.active = (this.active + step + count) % count;
            this.$nextTick(() => {
                this.$refs.list?.querySelector(`[data-index="${this.results[this.active]}"]`)?.scrollIntoView({ block: 'nearest' });
            });
        },

        go(index = this.results[this.active]) {
            const item = this.items[index];

            if (item?.url) {
                window.location.assign(item.url);
            }
        },
    }));
});

const livewirePresent = window.Livewire
    || document.querySelector('[wire\\:id], script[src*="livewire"]');

if (! livewirePresent && ! window.Alpine) {
    window.Alpine = Alpine;

    Alpine.start();
}
