import { LayawayActivity } from '@/features/layaway/components/LayawayActivity';
import { render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

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

it('es una línea de tiempo vertical, sin columna vacía a la izquierda, y una transferencia enlaza al otro apartado', () => {
    render(
        <LayawayActivity
            events={[
                {
                    kind: 'payment_transferred_out',
                    title: 'Pago transferido al apartado AP-9X2 · $30,00',
                    detail: 'Motivo: era de la otra clienta',
                    actor: 'Anibal',
                    at: '2026-11-10T10:00:00-04:00',
                    link: { url: '/backoffice/apartados/AP-9X2', label: 'AP-9X2' },
                },
                {
                    kind: 'safeguard_recorded',
                    title: 'Resguardo registrado · Binance (USDT)',
                    detail: 'Pago de $30,00',
                    actor: 'Anibal',
                    at: '2026-11-09T10:00:00-04:00',
                },
            ]}
        />,
    );

    const timeline = screen.getByRole('list', { name: 'Actividad del apartado' });
    expect(timeline.tagName).toBe('OL');

    const [transfer, safeguard] = within(timeline).getAllByRole('listitem');
    expect(transfer).toHaveTextContent('Pago transferido al apartado AP-9X2 · $30,00');
    expect(within(transfer!).getByRole('link', { name: 'Ver el apartado AP-9X2' })).toHaveAttribute('href', '/backoffice/apartados/AP-9X2');
    expect(safeguard).toHaveTextContent('Resguardo registrado · Binance (USDT)');
    expect(safeguard).toHaveTextContent('Anibal');
    expect(transfer!.firstElementChild).toHaveAttribute('aria-hidden', 'true');
    expect(safeguard!.querySelectorAll(':scope > span[aria-hidden="true"]')).toHaveLength(1);
    expect(transfer!.lastElementChild).toHaveClass('flex-1');
});
