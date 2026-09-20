import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',     // dashboard / admin / user shell
                'resources/css/frontend.css', // public landing pages — independent entry
                'resources/js/app.js',
                // Standalone language-switcher entry so the PUBLIC frontend (which
                // loads frontend.css only, not app.js) can still power the header
                // dropdown. Same module app.js already imports — no logic drift.
                'resources/js/locale-switcher.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    experimental: {
        renderBuiltUrl(filename, { hostType }) {
            return { relative: true };
        },
    },
});
