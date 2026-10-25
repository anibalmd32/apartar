import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { centsUsd } from '@/lib/money';
import { type MoneyView } from '@/types/money';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ usePage }));

function pageWith(display: 'usd_primary' | 'ves_primary', rate: number | null = 42.5): void {
    usePage.mockReturnValue({
        props: {
            currency: {
                display,
                timezone: 'America/Caracas',
                rate: { rate, source: 'bcv', sourceLabel: 'BCV', publishedAt: '2026-09-19T09:00:00-04:00', isStale: false },
            },
        },
    });
}

const price: MoneyView = { cents: centsUsd(2000), usd: '$20,00', ves: 'Bs. 850,00' };

beforeEach(() => {
    localStorage.clear();
});

describe('el importe con la moneda preferida delante', () => {
    it('escribe el dólar primero en modo usd_primary', () => {
        pageWith('usd_primary');

        render(<MoneyLabel money={price} />);

        expect(screen.getByText(/\$20,00/)).toBeInTheDocument();
        expect(screen.getByText(/\(Bs\. 850,00\)/)).toBeInTheDocument();
    });

    it('escribe el bolívar primero en modo ves_primary', () => {
        pageWith('ves_primary');

        render(<MoneyLabel money={price} />);

        expect(screen.getByText(/Bs\. 850,00/)).toBeInTheDocument();
        expect(screen.getByText(/\(\$20,00\)/)).toBeInTheDocument();
    });

    it('compone el par exacto en los dos sentidos', () => {
        const big: MoneyView = { cents: centsUsd(123456), usd: '$1.234,56', ves: 'Bs. 52.468,80' };

        pageWith('usd_primary');
        const { container, unmount } = render(<MoneyLabel money={big} />);
        expect(container.textContent).toBe('$1.234,56 (Bs. 52.468,80)');
        unmount();

        pageWith('ves_primary');
        const second = render(<MoneyLabel money={big} />);
        expect(second.container.textContent).toBe('Bs. 52.468,80 ($1.234,56)');
    });
});

describe('sin tasa', () => {
    const withoutVes: MoneyView = { cents: centsUsd(2000), usd: '$20,00', ves: null };

    it('muestra solo el dólar', () => {
        pageWith('usd_primary', null);

        const { container } = render(<MoneyLabel money={withoutVes} />);

        expect(container.textContent).toBe('$20,00');
    });

    it('sigue mostrando solo el dólar aunque la preferencia sea bolívares', () => {
        pageWith('ves_primary', null);

        const { container } = render(<MoneyLabel money={withoutVes} />);

        expect(container.textContent).toBe('$20,00');
    });

    it('no escribe ni un cero ni un guion ni un paréntesis vacío', () => {
        pageWith('ves_primary', null);

        const { container } = render(<MoneyLabel money={withoutVes} />);

        expect(container.textContent).not.toContain('Bs.');
        expect(container.textContent).not.toContain('—');
        expect(container.textContent).not.toContain('()');
    });
});
