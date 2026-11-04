import { useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const mediaQuery = (): MediaQueryList | null => (typeof window.matchMedia === 'function' ? window.matchMedia('(prefers-color-scheme: dark)') : null);

const prefersDark = () => mediaQuery()?.matches ?? false;

export const APPEARANCE_EVENT = 'apartar:appearance';

export function resolvesToDark(appearance: Appearance): boolean {
    return appearance === 'dark' || (appearance === 'system' && prefersDark());
}

const applyTheme = (appearance: Appearance) => {
    const isDark = resolvesToDark(appearance);

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.dataset.theme = isDark ? 'apartar-dark' : 'apartar';
    window.dispatchEvent(new CustomEvent<Appearance>(APPEARANCE_EVENT, { detail: appearance }));
};

const handleSystemThemeChange = () => {
    const currentAppearance = localStorage.getItem('appearance') as Appearance;
    applyTheme(currentAppearance || 'system');
};

export function initializeTheme() {
    const savedAppearance = (localStorage.getItem('appearance') as Appearance) || 'system';

    applyTheme(savedAppearance);

    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>('system');

    const updateAppearance = (mode: Appearance) => {
        setAppearance(mode);
        localStorage.setItem('appearance', mode);
        applyTheme(mode);
    };

    useEffect(() => {
        const savedAppearance = localStorage.getItem('appearance') as Appearance | null;
        setAppearance(savedAppearance || 'system');

        const follow = (event: Event) => setAppearance((event as CustomEvent<Appearance>).detail);
        window.addEventListener(APPEARANCE_EVENT, follow);

        return () => window.removeEventListener(APPEARANCE_EVENT, follow);
    }, []);

    return { appearance, updateAppearance };
}
