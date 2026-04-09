import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    server: {
        watch: {
            ignored: [
                '**/vendor/**',
                '**/node_modules/**',
            ],
        },
    },
    plugins: [
        tailwindcss(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/customization/editor.js',
                'resources/js/ckeditor.js',
            ],
            refresh: true,
        }),
    ],
});
