import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/zc.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
    ],
    server: {
        watch: {
            // Kept from the Laravel default: compiled Blade views would
            // otherwise retrigger the refresh plugin in a loop.
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
