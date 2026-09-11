import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

// El servidor de desarrollo corre dentro del contenedor `node`, así que
// escucha en 0.0.0.0; el HMR debe anunciarse con el host que ve el navegador
// del anfitrión, no con el nombre del servicio (REQ-INF-004 RB-7 y SP-5).
const hmrHost = process.env.VITE_HOST ?? 'localhost';

export default defineConfig({
    plugins: [
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
            // Los bind mounts de Docker no propagan eventos inotify.
            usePolling: true,
            interval: 300,
        },
    },
});
