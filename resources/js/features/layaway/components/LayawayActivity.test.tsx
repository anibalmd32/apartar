import { LayawayActivity } from '@/features/layaway/components/LayawayActivity';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

it('pinta los hitos en el orden del servidor, con su detalle y su autor', () => {
    render(
        <LayawayActivity
            timezone="America/Caracas"
            events={[
                {
                    kind: 'receipt_rejected',
                    title: 'Comprobante rechazado',
                    detail: 'No se lee.',
                    actor: 'Ana Pérez',
                    at: '2026-10-16T14:00:00-04:00',
                },
                { kind: 'created', title: 'Apartado creado', detail: 'Desde el catálogo «Otoño»', actor: null, at: '2026-09-01T09:00:00-04:00' },
            ]}
        />,
    );

    const items = screen.getAllByRole('listitem');
    expect(items[0]).toHaveTextContent('Comprobante rechazado');
    expect(items[0]).toHaveTextContent('No se lee.');
    expect(items[0]).toHaveTextContent('Ana Pérez');
    expect(items[1]).toHaveTextContent('Apartado creado');
});

it('sin hitos no hay tarjeta', () => {
    const { container } = render(<LayawayActivity events={[]} />);

    expect(container).toBeEmptyDOMElement();
});
