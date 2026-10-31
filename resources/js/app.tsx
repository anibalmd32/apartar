import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { type route as routeFn } from 'ziggy-js';
import { AppRoot } from './app-root';
import { Toaster } from './components/ui/toaster';
import { initializeTheme } from './hooks/use-appearance';
import { watchBrokenImages } from './lib/broken-images';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Apartar';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <AppRoot>
                <App {...props} />
                <Toaster initialPage={props.initialPage} />
            </AppRoot>,
        );
    },
    progress: {
        color: '#5046e5',
        delay: 150,
    },
});

initializeTheme();
watchBrokenImages();
