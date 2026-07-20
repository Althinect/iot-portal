import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/iot-dashboard/page.css',
                'resources/js/iot-dashboard/dashboard-page.js',
                'resources/css/iot-dashboard/demo-scada.css',
                'resources/js/iot-dashboard/demo-scada.js',
                'resources/css/iot-dashboard/classic-scada-network.css',
                'resources/js/iot-dashboard/classic-scada-network.jsx',
            ],
            refresh: true,
        }),
    ],
    server: {
        host: '127.0.0.1',
        origin: 'http://127.0.0.1:5173',
        cors: {
            origin: 'http://localhost:8081',
        },
        hmr: {
            host: '127.0.0.1',
        },
    },
});
