import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

// Docker'da vite konteyneri 5173 da tinglaydi, host'da boshqa portga chiqadi
// (5173 band). VITE_PORT host portini beradi (docker-compose.yml).
const hostPort = Number(process.env.VITE_PORT || 5173);

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/miniapp/main.jsx',
                'resources/js/kitchen/main.jsx',
            ],
            refresh: true,
        }),
        react(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // Blade'dagi @vite shu manzilni ishlatadi (browser host'dan ko'radi).
        origin: `http://localhost:${hostPort}`,
        cors: true,
        hmr: { host: 'localhost', clientPort: hostPort },
        watch: {
            usePolling: true,
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});
