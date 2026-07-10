import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    const packageMatch = id.match(/node_modules\/@ckeditor\/(ckeditor5-[^/]+)/);

                    if (!packageMatch) {
                        return undefined;
                    }

                    const packageName = packageMatch[1];

                    if (['ckeditor5-utils', 'ckeditor5-icons', 'ckeditor5-theme-lark'].includes(packageName)) {
                        return 'ckeditor-foundation';
                    }

                    if (['ckeditor5-engine', 'ckeditor5-core', 'ckeditor5-watchdog'].includes(packageName)) {
                        return 'ckeditor-engine';
                    }

                    if (['ckeditor5-ui', 'ckeditor5-widget', 'ckeditor5-editor-classic'].includes(packageName)) {
                        return 'ckeditor-features';
                    }

                    if (['ckeditor5-image', 'ckeditor5-upload', 'ckeditor5-media-embed'].includes(packageName)) {
                        return 'ckeditor-media';
                    }

                    if (packageName === 'ckeditor5-table') {
                        return 'ckeditor-tables';
                    }

                    if (['ckeditor5-link', 'ckeditor5-list', 'ckeditor5-find-and-replace', 'ckeditor5-special-characters'].includes(packageName)) {
                        return 'ckeditor-features';
                    }

                    return 'ckeditor-features';
                },
            },
        },
    },
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
