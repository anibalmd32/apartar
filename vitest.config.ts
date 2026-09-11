import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

/**
 * Vitest + React Testing Library (`REQ-QAX-003`, `ASU-QAX-02`).
 *
 * Los tests viven junto al código que prueban (`resources/js/**\/*.test.tsx`),
 * no en un árbol paralelo: así un componente y su prueba se mueven juntos.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        // Ruta desde la raíz del proyecto, que es como Vite resuelve un alias
        // absoluto. Evita `node:url` y con él una dependencia de `@types/node`
        // que no está en 05 §3.3.
        alias: {
            '@': '/resources/js',
        },
    },
    test: {
        globals: true,
        environment: 'jsdom',
        setupFiles: ['./resources/js/test/setup.ts'],
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
        css: false,
        restoreMocks: true,
    },
});
