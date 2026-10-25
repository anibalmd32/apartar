import { RateStamp } from '@/features/currency/components/RateStamp';
import { type RateView } from '@/types/exchange';
import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

function rateView(overrides: Partial<RateView> = {}): RateView {
    return {
        rate: 42.5,
        source: 'bcv',
        sourceLabel: 'BCV',
        publishedAt: '2026-09-18T09:00:00-04:00',
        isStale: false,
        ...overrides,
    };
}

it('escribe la fuente y la fecha de publicación', () => {
    const { container } = render(<RateStamp rate={rateView()} timezone="America/Caracas" />);

    expect(container.textContent).toContain('Tasa BCV del 18/09 09:00');
});

it('usa el nombre que manda el servidor', () => {
    const { container } = render(<RateStamp rate={rateView({ source: 'parallel', sourceLabel: 'Paralelo' })} timezone="America/Caracas" />);

    expect(container.textContent).toContain('Tasa Paralelo del');
});

it('escribe la hora en la zona del negocio', () => {
    const caracas = render(<RateStamp rate={rateView()} timezone="America/Caracas" />);
    expect(caracas.container.textContent).toContain('18/09 09:00');

    const madrid = render(<RateStamp rate={rateView()} timezone="Europe/Madrid" />);
    expect(madrid.container.textContent).toContain('18/09 15:00');
});

describe('con una tasa vieja', () => {
    it('añade la advertencia sin quitar el sello', () => {
        const { container } = render(<RateStamp rate={rateView({ isStale: true })} timezone="America/Caracas" />);

        expect(container.textContent).toContain('Tasa BCV del 18/09 09:00');
        expect(container.textContent).toContain('desactualizada');
    });

    it('no la añade cuando la tasa es reciente', () => {
        const { container } = render(<RateStamp rate={rateView()} timezone="America/Caracas" />);

        expect(container.textContent).not.toContain('desactualizada');
    });
});

it('no pinta nada cuando no hay tasa', () => {
    const { container } = render(<RateStamp rate={rateView({ rate: null, publishedAt: null })} timezone="America/Caracas" />);

    expect(container.textContent).toBe('');
});
