import { DataTable } from '@/features/data-table/components/DataTable';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { inboxColumns } from '@/features/receipts/components/inboxColumns';
import { type TableMeta } from '@/types/data-table';
import { type ReceiptInboxRow } from '@/types/receipt';
import { render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { get: vi.fn(), on: vi.fn(() => () => undefined) },
    Link: ({ href, children, className, ...props }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className} {...props}>
            {children}
        </a>
    ),
}));

const META: TableMeta = { page: 1, perPage: 25, sort: null, filters: {}, total: 1, lastPage: 1, hasMore: false, ignoredFilters: [] };

const RECEIPT: ReceiptInboxRow = {
    id: 9,
    reviewUrl: '/comprobantes/9',
    status: 'pending_review',
    statusLabel: 'En revisión',
    overdue: false,
    submittedAt: '2026-10-31T12:00:00-04:00',
    paidAt: '2026-10-30',
    layaway: { code: 'AP-9QCLUBE2', url: '/apartados/AP-9QCLUBE2', cancelled: false },
    customer: { fullName: 'Sofía Rinaldi', documentNumber: 'V12345678' },
    method: 'pago_movil',
    methodLabel: 'Pago móvil',
    bank: 'Banesco',
    reference: '123456',
    paymentAccount: null,
    declaredAmount: '1234.5',
    declaredCurrency: 'USD',
    file: { url: '/archivo.jpg', kind: 'image' },
};

function Inbox() {
    const table = useServerTable({ rows: [RECEIPT], meta: META, columns: inboxColumns(Date.parse('2026-11-02T12:00:00-04:00')) });

    return <DataTable label="Bandeja" table={table} empty="Nada." rowHref={(row) => row.reviewUrl} />;
}

it('en el celular el comprobante es una tarjeta con el monto declarado arriba que lleva a revisarlo', () => {
    render(<Inbox />);

    const card = within(screen.getByRole('list', { name: 'Bandeja' })).getByRole('listitem');
    const links = within(card).getAllByRole('link');

    expect(links).toHaveLength(1);
    expect(links[0]).toHaveAccessibleName('Sofía Rinaldi');
    expect(links[0]).toHaveAttribute('href', '/comprobantes/9');
    expect(card).toHaveTextContent('$1.234,50');
    expect(card).toHaveTextContent('AP-9QCLUBE2 · Pago móvil');
    expect(card).toHaveTextContent('En revisión');
    expect(card).toHaveTextContent('hace 2 d');
    expect(card).not.toHaveTextContent('Sin indicar');
    expect(within(card).queryByRole('link', { name: 'Revisar' })).toBeNull();
});
