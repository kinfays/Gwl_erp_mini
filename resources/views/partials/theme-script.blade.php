{{-- Runs before first paint so the page never flashes the wrong theme or sidebar width. --}}
<script>
    (() => {
        const root = document.documentElement;
        let theme = null;
        let sidebar = null;

        try {
            theme = localStorage.getItem('gwl-theme');
            sidebar = localStorage.getItem('gwl-sidebar');
        } catch (error) {
            // Storage can be blocked; fall back to the OS preference.
        }

        const dark = theme ? theme === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;

        root.classList.toggle('dark', dark);
        root.style.colorScheme = dark ? 'dark' : 'light';

        if (sidebar === 'collapsed') {
            root.dataset.sidebar = 'collapsed';
        }
    })();
</script>
