import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/edit-new/index.css',
                'resources/js/edit-new/main.js',
                'resources/css/edit-pdfjs/index.css',
                'resources/js/edit-pdfjs/main.js',
                'resources/css/edit-new-pdfjs/index.css',
                'resources/js/edit-new-pdfjs/main.js',
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
});
