import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';

const hmrHost = process.env.VITE_HOST ?? 'localhost';

function devServerNotice(appUrl) {
    const body = JSON.stringify({
        servicio: 'Servidor de assets de Apartar en desarrollo',
        app: appUrl,
    });

    return {
        name: 'apartar-dev-server-notice',
        configureServer(server) {
            server.middlewares.use((request, response, next) => {
                if (request.url !== '/' && request.url !== '/index.html') {
                    next();

                    return;
                }

                response.statusCode = 200;
                response.setHeader('Content-Type', 'application/json; charset=utf-8');
                response.end(body);
            });
        },
    };
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return {
        plugins: [
            devServerNotice(env.APP_URL ?? 'http://localhost:8000'),
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                ssr: 'resources/js/ssr.jsx',
                refresh: true,
            }),
            react(),
            tailwindcss(),
        ],
        esbuild: {
            jsx: 'automatic',
        },
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            hmr: {
                host: hmrHost,
            },
            watch: {
                usePolling: true,
                interval: 300,
            },
        },
    };
});
