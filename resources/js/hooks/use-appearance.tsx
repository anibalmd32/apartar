import { useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

export const APPEARANCE_EVENT = 'apartar:appearance';

const STORAGE_KEY = 'appearance';

const mediaQuery = (): MediaQueryList | null => (typeof window.matchMedia === 'function' ? window.matchMedia('(prefers-color-scheme: dark)') : null);

export const prefersDark = (): boolean => mediaQuery()?.matches ?? false;

export function readAppearance(): Appearance {
    try {
        const saved = window.localStorage.getItem(STORAGE_KEY);

        return saved === 'light' || saved === 'dark' ? saved : 'system';
    } catch {
        return 'system';
    }
}

function writeAppearance(appearance: Appearance): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, appearance);
    } catch {
        return;
    }
}

export function resolvesToDark(appearance: Appearance): boolean {
    return appearance === 'dark' || (appearance === 'system' && prefersDark());
}

function pageIsDark(): boolean {
    return document.documentElement.dataset.theme === 'apartar-dark';
}

const applyTheme = (appearance: Appearance) => {
    const isDark = resolvesToDark(appearance);

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.dataset.theme = isDark ? 'apartar-dark' : 'apartar';
    window.dispatchEvent(new CustomEvent<Appearance>(APPEARANCE_EVENT, { detail: appearance }));
};

export function initializeTheme() {
    applyTheme(readAppearance());

    mediaQuery()?.addEventListener('change', () => applyTheme(readAppearance()));
    window.addEventListener('storage', (event) => {
        if (event.key === STORAGE_KEY || event.key === null) {
            applyTheme(readAppearance());
        }
    });
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>(readAppearance);
    const [isDark, setIsDark] = useState<boolean>(() => pageIsDark() || resolvesToDark(readAppearance()));

    const updateAppearance = (mode: Appearance) => {
        writeAppearance(mode);
        applyTheme(mode);
    };

    useEffect(() => {
        const follow = (event: Event) => {
            setAppearance((event as CustomEvent<Appearance>).detail);
            setIsDark(pageIsDark());
        };

        setIsDark(pageIsDark());
        window.addEventListener(APPEARANCE_EVENT, follow);

        return () => window.removeEventListener(APPEARANCE_EVENT, follow);
    }, []);

    return { appearance, isDark, updateAppearance };
}
