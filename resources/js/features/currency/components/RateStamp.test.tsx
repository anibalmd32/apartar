import { RateStamp } from '@/features/currency/components/RateStamp';
import { type RateView } from '@/types/exchange';
import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

/**
 * El sello que hace verificable un precio proyectado (`REQ-FX-005` RB-2 y
 * RB-3).
 */

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

/**
 * **El nombre viene del servidor**, no de un diccionario escrito aquí: con dos
 * censos, esta pantalla y el selector de ajustes podrían llamar cosas
 * distintas a la misma tasa.
 */
it('usa el nombre que manda el servidor', () => {
    const { container } = render(<RateStamp rate={rateView({ source: 'parallel', sourceLabel: 'Paralelo' })} timezone="America/Caracas" />);

    expect(container.textContent).toContain('Tasa Paralelo del');
});

/**
 * **La hora es la del negocio**, no la de quien mira. El BCV publica en hora
 * de Venezuela y el catálogo es de un negocio venezolano: el mismo instante
 * tiene que leerse igual desde Caracas y desde Madrid.
 */
it('escribe la hora en la zona del negocio', () => {
    const caracas = render(<RateStamp rate={rateView()} timezone="America/Caracas" />);
    expect(caracas.container.textContent).toContain('18/09 09:00');

    const madrid = render(<RateStamp rate={rateView()} timezone="Europe/Madrid" />);
    expect(madrid.container.textContent).toContain('18/09 15:00');
});

/**
 * **RB-3 · el aviso de antigüedad acompaña, no bloquea.** La tasa se sigue
 * usando y el importe se sigue mostrando; lo que cambia es la advertencia.
 */
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

/**
 * Sin tasa no hay nada que sellar. Quien explica la ausencia es el toggle
 * deshabilitado con su tooltip, que es donde la persona va a buscar por qué no
 * ve bolívares.
 */
it('no pinta nada cuando no hay tasa', () => {
    const { container } = render(<RateStamp rate={rateView({ rate: null, publishedAt: null })} timezone="America/Caracas" />);

    expect(container.textContent).toBe('');
});
