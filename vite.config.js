import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/voice/teacher.js',
                'resources/js/voice/student.js',
            ],
            refresh: [
                'resources/views/**/*.blade.php',
                'resources/js/**/*.js',
                'resources/css/**/*.css',
            ],
        }),
    ],
    server: {
        host: '0.0.0.0', // allows external access (important for https or Docker)
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'backend.raay.sa', // your actual domain (important for HMR over HTTPS)
            protocol: 'wss',
            // // port: 6001, // same as your Laravel Echo server if using secure WebSockets
        },
    },
});
