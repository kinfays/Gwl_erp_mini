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

    // <x-form.searchable-select>: a type-to-search picker bound to a Livewire property (`selected`, passed in as an
    // entangle). With `options` it filters them in the browser; with `method` it asks that Livewire method for
    // matches (debounced; the server scopes and limits them). Labels are only ever written with x-text, so nothing
    // from the server is treated as HTML, and the server re-checks the chosen id on save.
    alpine.data('searchableSelect', (config = {}) => ({
        open: false,
        query: '',
        activeIndex: -1,
        selected: config.selected,
        options: config.options || [],
        method: config.method || null,
        initialLabel: config.initialLabel || '',
        debounce: config.debounce ?? 250,
        listId: config.listId,
        results: [],
        current: null,
        loading: false,
        timer: null,
        requestId: 0,

        init() {
            this.syncFromSelected();

            this.$watch('selected', () => {
                if (! this.open) {
                    this.syncFromSelected();
                }
            });
        },

        hasValue() {
            return this.selected !== null && this.selected !== undefined && this.selected !== '';
        },

        syncFromSelected() {
            if (! this.hasValue()) {
                this.current = null;
                this.query = '';

                return;
            }

            const same = (option) => option && String(option.value) === String(this.selected);
            const found = this.options.find(same) || (same(this.current) ? this.current : null);

            if (found) {
                this.current = found;
                this.query = found.label;
            } else if (this.initialLabel) {
                this.current = { value: this.selected, label: this.initialLabel };
                this.query = this.initialLabel;
                this.initialLabel = ''; // one-shot: later values must come from a real option
            } else {
                this.current = null;
                this.query = '';
            }
        },

        get visible() {
            if (this.method) {
                return this.results;
            }

            const needle = this.query.trim().toLowerCase();
            const matches = needle && ! (this.current && this.query === this.current.label)
                ? this.options.filter((option) => `${option.label} ${option.description || ''}`.toLowerCase().includes(needle))
                : this.options;

            return matches.slice(0, 50);
        },

        openMenu() {
            this.open = true;
            this.activeIndex = this.visible.length ? 0 : -1;

            if (this.method && this.results.length === 0) {
                this.fetchNow();
            }
        },

        inputChanged() {
            this.open = true;

            // Typing over a chosen value un-chooses it, so a half-typed label can never be saved.
            if (this.current && this.query !== this.current.label) {
                this.selected = '';
                this.current = null;
            }

            if (this.method) {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.fetchNow(), this.debounce);
            } else {
                this.activeIndex = this.visible.length ? 0 : -1;
            }
        },

        async fetchNow() {
            const id = ++this.requestId;
            const term = this.current && this.query === this.current.label ? '' : this.query.trim();

            this.loading = true;

            try {
                const rows = await this.$wire[this.method](term);

                if (id === this.requestId) {
                    this.results = Array.isArray(rows) ? rows : [];
                    this.activeIndex = this.results.length ? 0 : -1;
                }
            } catch (error) {
                if (id === this.requestId) {
                    this.results = [];
                }
            } finally {
                if (id === this.requestId) {
                    this.loading = false;
                }
            }
        },

        choose(option) {
            this.selected = option.value;
            this.current = option;
            this.query = option.label;
            this.open = false;
            this.activeIndex = -1;
        },

        clear() {
            this.selected = '';
            this.current = null;
            this.query = '';
            this.open = false;
            this.activeIndex = -1;
            this.results = [];
            this.$refs.input.focus();
        },

        move(step) {
            const count = this.visible.length;

            if (! count) {
                this.activeIndex = -1;

                return;
            }

            if (! this.open) {
                this.openMenu();

                return;
            }

            this.activeIndex = (this.activeIndex + step + count) % count;
            this.$nextTick(() => document.getElementById(`${this.listId}-${this.activeIndex}`)?.scrollIntoView({ block: 'nearest' }));
        },

        chooseActive() {
            if (! this.open) {
                this.openMenu();

                return;
            }

            const option = this.visible[this.activeIndex];

            if (option) {
                this.choose(option);
            }
        },

        close() {
            this.open = false;
            // Leave the box showing the chosen label (or empty), never a half-typed search.
            this.syncFromSelected();
        },
    }));
});

const livewirePresent = window.Livewire
    || document.querySelector('[wire\\:id], script[src*="livewire"]');

if (! livewirePresent && ! window.Alpine) {
    window.Alpine = Alpine;

    Alpine.start();
}
