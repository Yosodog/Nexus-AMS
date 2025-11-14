import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

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
