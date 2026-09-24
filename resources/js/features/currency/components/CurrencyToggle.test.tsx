import { CurrencyToggle } from '@/features/currency/components/CurrencyToggle';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * El interruptor de moneda (`REQ-FX-004` RB-3, `REQ-FX-005` SP-2).
 */

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ usePage }));

function pageWith(display: 'usd_primary' | 'ves_primary', rate: number | null): void {
    usePage.mockReturnValue({
        props: {
            currency: {
                display,
                timezone: 'America/Caracas',
                rate: { rate, source: 'bcv', sourceLabel: 'BCV', publishedAt: rate === null ? null : '2026-09-19T09:00:00-04:00', isStale: false },
            },
        },
    });
}

beforeEach(() => {
    localStorage.clear();
    // Desde `T-035` la cookie que escribe el toggle gana a la prop al montar:
    // la que dejó el test anterior no puede decidir el siguiente.
    document.cookie = 'currency_display=; path=/; max-age=0';
});

it('cambia la moneda al pulsarlo, sin recargar', () => {
    pageWith('usd_primary', 42.5);

    render(<CurrencyToggle />);

    const toggle = screen.getByRole('button');
    expect(toggle).toHaveAttribute('aria-pressed', 'false');

    fireEvent.click(toggle);

    expect(toggle).toHaveAttribute('aria-pressed', 'true');
});

/**
 * **SP-2 · sin tasa se deshabilita, y no desaparece.** Es la diferencia entre
 * un control apagado —que se explica solo con su tooltip— y un control que no
 * está, que deja a la persona buscando qué hizo mal.
 */
describe('sin tasa disponible', () => {
    it('sigue estando, deshabilitado', () => {
        pageWith('usd_primary', null);

        render(<CurrencyToggle />);

        expect(screen.getByRole('button')).toBeDisabled();
    });

    it('explica por qué con un tooltip', () => {
        pageWith('usd_primary', null);

        render(<CurrencyToggle />);

        expect(screen.getByRole('button')).toHaveAttribute('title', expect.stringContaining('No hay tasa'));
    });

    it('no cambia nada al intentar pulsarlo', () => {
        pageWith('usd_primary', null);

        render(<CurrencyToggle />);

        const toggle = screen.getByRole('button');

        fireEvent.click(toggle);

        expect(toggle).toHaveAttribute('aria-pressed', 'false');
        expect(localStorage.getItem('currency_display')).toBeNull();
    });
});

it('está habilitado cuando hay tasa, aunque sea vieja', () => {
    pageWith('usd_primary', 42.5);

    render(<CurrencyToggle />);

    expect(screen.getByRole('button')).toBeEnabled();
});
