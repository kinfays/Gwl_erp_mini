import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/charts.js',
                'resources/js/signature-pad.js',
                // Regional Blog: its own stylesheet and fonts; the lightbox and the editor are separate so they only load where used.
                'resources/css/blog.css',
                'resources/js/blog.js',
                'resources/js/blog-lightbox.js',
                'resources/js/blog-editor.js',
            ],
            refresh: true,
        }),
    ],
});
