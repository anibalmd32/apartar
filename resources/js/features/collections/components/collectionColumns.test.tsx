import { installmentColumns } from '@/features/collections/components/collectionColumns';
import { directoryColumns } from '@/features/customers/components/directoryColumns';
import { DataTable } from '@/features/data-table/components/DataTable';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { centsUsd } from '@/lib/money';
import { type CollectionInstallmentRow } from '@/types/collections';
import { type CustomerDirectoryRow } from '@/types/customers';
import { type DataTableColumn, type TableMeta } from '@/types/data-table';
import { render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: {} }),
    router: { get: vi.fn(), on: vi.fn(() => () => undefined) },
    Link: ({ href, children, className, ...props }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className} {...props}>
            {children}
        </a>
    ),
}));

beforeEach(() => {
    vi.stubGlobal('route', (name: string) => `/${name}`);
});

const META: TableMeta = { page: 1, perPage: 25, sort: null, filters: {}, total: 1, lastPage: 1, hasMore: false, ignoredFilters: [] };

const usd = (cents: number, label: string) => ({ cents: centsUsd(cents), usd: label, ves: null });

function List<TRow extends { id: number }>({
    rows,
    columns,
    href,
    cards = false,
}: {
    rows: TRow[];
    columns: DataTableColumn<TRow>[];
    href: (row: TRow) => string;
    cards?: boolean;
}) {
    const table = useServerTable({ rows, meta: META, columns });

    return <DataTable label="Lista" table={table} empty="Nada." rowHref={href} mobileVariant={cards ? 'card' : undefined} />;
}

const card = () => within(screen.getByRole('list', { name: 'Lista' })).getByRole('listitem');

const INSTALLMENT: CollectionInstallmentRow = {
    id: 1,
    customer: { name: 'José Gregorio Martínez', phone: '04241234502', phoneLabel: '0424-1234502', url: '/clientes/1', chatUrl: '/chat/1' },
    layaway: { code: 'AP-YZE96F5Q', url: '/apartados/AP-YZE96F5Q' },
    number: 2,
    of: 4,
    dueDate: '2026-10-28',
    amount: usd(1_450, '$14,50'),
    paid: usd(0, '$0,00'),
    daysLate: 5,
    pending: usd(1_450, '$14,50'),
    productName: 'Vestido negro',
    delivery: { deliveredAt: null, url: null },
};

it('en Cobranza la cuota es una tarjeta que lleva al apartado, con el monto, el atraso y un botón para llamar', () => {
    render(<List rows={[INSTALLMENT]} columns={installmentColumns} href={(row) => row.layaway.url} cards />);

    const row = card();
    const call = within(row).getByRole('link', { name: 'Llamar a José Gregorio Martínez al 0424-1234502' });

    expect(within(row).getByRole('link', { name: 'José Gregorio Martínez' })).toHaveAttribute('href', '/apartados/AP-YZE96F5Q');
    expect(call).toHaveAttribute('href', 'tel:04241234502');
    expect(call).toHaveClass('size-11');
    expect(row).toHaveTextContent('$14,50');
    expect(row).toHaveTextContent('5 días de atraso');
    expect(row).toHaveTextContent('Vestido negro · Cuota 2 de 4');
    expect(row).toHaveTextContent('28 oct 2026 · AP-YZE96F5Q');
    expect(row).toHaveTextContent('Importe');
    expect(row).not.toHaveTextContent('Pagado');
    expect(row).not.toHaveTextContent('$0,00');
});

it('en Cobranza las acciones de la tarjeta van al pie, centradas y solo con iconos', () => {
    render(
        <List
            rows={[{ ...INSTALLMENT, delivery: { deliveredAt: null, url: '/apartados/AP-YZE96F5Q/entregar' } }]}
            columns={installmentColumns}
            href={(row) => row.layaway.url}
            cards
        />,
    );

    const footer = card().querySelector('[data-card-actions]') as HTMLElement;
    const actions = [...within(footer).getAllByRole('link'), ...within(footer).getAllByRole('button')];

    expect(footer).toHaveClass('justify-center');
    expect(within(footer).getByRole('link', { name: 'Registrar un pago de AP-YZE96F5Q' })).toHaveAttribute('href', '/apartados/AP-YZE96F5Q');
    expect(within(footer).getByRole('link', { name: 'Escribir a José Gregorio Martínez por el chat' })).toHaveAttribute('href', '/chat/1');
    expect(within(footer).getByRole('button', { name: 'Marcar AP-YZE96F5Q como entregado' })).toBeInTheDocument();
    expect(actions).toHaveLength(5);
    actions.forEach((action) => expect(action).toHaveTextContent(/^$/));
});

it('en Cobranza cada cuota trae su recordatorio por WhatsApp con lo pendiente y el enlace al portal', () => {
    render(<List rows={[INSTALLMENT]} columns={installmentColumns} href={(row) => row.layaway.url} />);

    const reminder = within(card()).getByRole('link', { name: 'Recordarle a José Gregorio Martínez por WhatsApp' });
    const text = decodeURIComponent(reminder.getAttribute('href') ?? '');

    expect(reminder.getAttribute('href')).toMatch(/^https:\/\/wa\.me\/584241234502\?text=/);
    expect(text).toContain('Hola, José');
    expect(text).toContain('tienes $14,50 pendientes de tu apartado');
    expect(text).toContain('/portal.access');
});

it('en Cobranza lo abonado de una cuota aparece solo cuando hay algo', () => {
    render(<List rows={[{ ...INSTALLMENT, paid: usd(500, '$5,00') }]} columns={installmentColumns} href={(row) => row.layaway.url} />);

    expect(card()).toHaveTextContent('Abonado $5,00');
});

const CUSTOMER: CustomerDirectoryRow = {
    id: 3,
    name: 'Andrés Castillo',
    document: 'V-23.456.789',
    phone: '04141234567',
    phoneLabel: '0414-1234567',
    url: '/clientes/3',
    hasLinkedAccount: false,
    inArrears: false,
    activeLayaways: 0,
    committed: usd(0, '$0,00'),
    paid: usd(0, '$0,00'),
    remaining: usd(0, '$0,00'),
    overdueInstallments: 0,
    nextDueDate: null,
};

it('en Clientes la tarjeta muestra nombre y cédula, y esconde los montos en cero', () => {
    render(<List rows={[CUSTOMER]} columns={directoryColumns} href={(row) => row.url} />);

    const row = card();

    expect(within(row).getByRole('link', { name: 'Andrés Castillo' })).toHaveAttribute('href', '/clientes/3');
    expect(row).toHaveTextContent('V-23.456.789');
    expect(row).not.toHaveTextContent('$0,00');
    expect(row).not.toHaveTextContent('0414-1234567');
});

it('en Clientes el pendiente y las cuotas vencidas se ven en la tarjeta', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date(2026, 10, 2, 10));
    render(
        <List
            rows={[{ ...CUSTOMER, inArrears: true, remaining: usd(4_350, '$43,50'), overdueInstallments: 1, nextDueDate: '2026-10-28' }]}
            columns={directoryColumns}
            href={(row) => row.url}
        />,
    );

    expect(card()).toHaveTextContent('$43,50');
    expect(card()).toHaveTextContent('1 cuota vencida');
    expect(card()).toHaveTextContent('Venció el 28 oct 2026');
    expect(card()).not.toHaveTextContent('Vence el');
    expect(card()).not.toHaveTextContent('Atrasado');
    vi.useRealTimers();
});

it('en Clientes una cuota que aún no llega dice Vence el', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date(2026, 10, 2, 10));
    render(<List rows={[{ ...CUSTOMER, nextDueDate: '2026-11-02' }]} columns={directoryColumns} href={(row) => row.url} />);

    expect(card()).toHaveTextContent('Vence el 2 nov 2026');
    vi.useRealTimers();
});
