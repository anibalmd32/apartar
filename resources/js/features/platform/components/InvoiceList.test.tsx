import { InvoiceList } from '@/features/platform/components/InvoiceList';
import { centsUsd } from '@/lib/money';
import { type InvoiceRow } from '@/types/platform';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ router: { post: vi.fn() }, useForm: vi.fn() }));
vi.mock('@/components/ui/confirm-dialog', () => ({
    ConfirmDialog: ({ open, description }: { open: boolean; description?: string }) => (open ? <div role="dialog">{description}</div> : null),
}));

const usd = (cents: number, label: string) => ({ cents: centsUsd(cents), usd: label, ves: null });

const INVOICE: InvoiceRow = {
    id: 3,
    plan: 'pro',
    kind: 'renewal',
    status: 'pending',
    amount: usd(1_500, '$15,00'),
    paid: usd(0, '$0,00'),
    dueDate: '2026-10-12',
    periodEnd: '2026-11-12',
    voidReason: null,
};

it('cada factura dice la fecha en palabras, esconde lo pagado en cero y ofrece Anular como botón', () => {
    render(<InvoiceList invoices={[INVOICE]} />);

    const item = within(screen.getByRole('region', { name: 'Facturas' })).getByRole('listitem');
    const button = within(item).getByRole('button', { name: 'Anular la factura de pro que vence el 12 oct 2026' });

    expect(item).toHaveTextContent('Vence el 12 oct 2026');
    expect(item).not.toHaveTextContent('2026-10-12');
    expect(item).not.toHaveTextContent('Pagado');
    expect(button).toHaveClass('btn');
    expect(button).not.toHaveClass('btn-ghost');

    fireEvent.click(button);

    expect(screen.getByRole('dialog')).toHaveTextContent('que vence el 12 oct 2026');
});

it('lo pagado aparece cuando hay un abono', () => {
    render(<InvoiceList invoices={[{ ...INVOICE, paid: usd(500, '$5,00') }]} />);

    expect(screen.getByRole('listitem')).toHaveTextContent('Pagado $5,00');
});
