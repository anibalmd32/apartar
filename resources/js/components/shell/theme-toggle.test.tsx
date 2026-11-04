import { ThemeToggle } from '@/components/shell/theme-toggle';
import { APPEARANCE_EVENT, initializeTheme } from '@/hooks/use-appearance';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

let systemDark = false;
const listeners: Array<() => void> = [];

beforeEach(() => {
    systemDark = false;
    listeners.length = 0;
    window.localStorage.clear();
    document.documentElement.classList.remove('dark');
    document.documentElement.dataset.theme = 'apartar';
    vi.stubGlobal('matchMedia', () => ({
        get matches() {
            return systemDark;
        },
        addEventListener: (_: string, listener: () => void) => listeners.push(listener),
        removeEventListener: vi.fn(),
    }));
});

afterEach(() => vi.unstubAllGlobals());

it('en claro ofrece pasar a oscuro y al tocarlo cambia la página y lo recuerda', () => {
    window.localStorage.setItem('appearance', 'light');
    render(<ThemeToggle />);

    fireEvent.click(screen.getByRole('button', { name: 'Cambiar a modo oscuro' }));

    expect(document.documentElement.dataset.theme).toBe('apartar-dark');
    expect(document.documentElement).toHaveClass('dark');
    expect(window.localStorage.getItem('appearance')).toBe('dark');
    expect(screen.getByRole('button', { name: 'Cambiar a modo claro' })).toBeInTheDocument();
});

it('dos botones en la misma página se mantienen de acuerdo', () => {
    window.localStorage.setItem('appearance', 'light');
    render(
        <>
            <ThemeToggle />
            <ThemeToggle />
        </>,
    );

    fireEvent.click(screen.getAllByRole('button', { name: 'Cambiar a modo oscuro' })[0]!);

    expect(screen.getAllByRole('button', { name: 'Cambiar a modo claro' })).toHaveLength(2);
});

it('si el teléfono pasa solo a oscuro, el botón se entera y el primer toque vuelve a claro', () => {
    initializeTheme();
    render(<ThemeToggle />);

    systemDark = true;
    act(() => listeners.forEach((listener) => listener()));

    expect(document.documentElement.dataset.theme).toBe('apartar-dark');

    fireEvent.click(screen.getByRole('button', { name: 'Cambiar a modo claro' }));

    expect(document.documentElement.dataset.theme).toBe('apartar');
});

it('volver al modo que ya trae el sistema deja la página siguiendo al sistema', () => {
    window.localStorage.setItem('appearance', 'dark');
    document.documentElement.dataset.theme = 'apartar-dark';
    render(<ThemeToggle />);

    fireEvent.click(screen.getByRole('button', { name: 'Cambiar a modo claro' }));

    expect(window.localStorage.getItem('appearance')).toBe('system');
});

it('con el almacenamiento bloqueado el botón sigue cambiando el tema en vez de romper la página', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
        throw new DOMException('bloqueado', 'SecurityError');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new DOMException('bloqueado', 'SecurityError');
    });

    render(<ThemeToggle />);
    fireEvent.click(screen.getByRole('button', { name: 'Cambiar a modo oscuro' }));

    expect(document.documentElement.dataset.theme).toBe('apartar-dark');
    vi.restoreAllMocks();
});

it('lo que cambia otra pestaña llega a esta', () => {
    initializeTheme();
    render(<ThemeToggle />);

    window.localStorage.setItem('appearance', 'dark');
    act(() => {
        window.dispatchEvent(new StorageEvent('storage', { key: 'appearance' }));
    });

    expect(document.documentElement.dataset.theme).toBe('apartar-dark');
    expect(screen.getByRole('button', { name: 'Cambiar a modo claro' })).toBeInTheDocument();
    expect(APPEARANCE_EVENT).toBe('apartar:appearance');
});
