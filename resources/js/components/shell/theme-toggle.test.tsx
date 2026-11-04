import { ThemeToggle } from '@/components/shell/theme-toggle';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it } from 'vitest';

beforeEach(() => {
    window.localStorage.clear();
    document.documentElement.classList.remove('dark');
    document.documentElement.dataset.theme = 'apartar';
});

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
