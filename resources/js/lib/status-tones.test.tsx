import { CustomerLayaways } from '@/features/customers/components/CustomerLayaways';
import { CustomerReceipts } from '@/features/customers/components/CustomerReceipts';
import { inboxColumns } from '@/features/receipts/components/inboxColumns';
import { centsUsd } from '@/lib/money';
import { INVOICE_TONES, LAYAWAY_TONES, RECEIPT_TONES, toneOf } from '@/lib/status-tones';
import { inboxRow } from '@/test/receipt-inbox-fixtures';
import { type CustomerLayawayRow, type CustomerReceiptRow } from '@/types/customers';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/', props: { errors: {}, currency: { display: 'usd_primary', rate: { rate: null } } } }),
    router: { post: vi.fn() },
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

const money = (usd: string) => ({ cents: centsUsd(0), usd, ves: null });

const LAYAWAY: CustomerLayawayRow = {
    code: 'AP-7K3M9QRT',
    url: '/apartados/AP-7K3M9QRT',
    status: 'overdue',
    statusLabel: 'Atrasado',
    productName: 'Nevera Mabe',
    catalogName: null,
    total: money('$100,00'),
    paid: money('$40,00'),
    remaining: money('$60,00'),
    nextDueDate: null,
    createdAt: null,
    cancellationReason: null,
    cancelledBy: null,
    resendCodeUrl: null,
};

const RECEIPT: CustomerReceiptRow = {
    id: 1,
    url: '/comprobantes/1',
    submittedAt: '2026-10-12',
    layawayCode: 'AP-7K3M9QRT',
    status: 'pending_review',
    statusLabel: 'En revisión',
    declared: '$30,00',
    paidToAccount: null,
    rejectionReason: null,
    reviewedBy: null,
    fileUrl: '/archivo',
};

describe('un estado tiene el mismo color en todas las pantallas', () => {
    it('un apartado en mora es rojo también en la ficha del cliente', () => {
        render(<CustomerLayaways layaways={[LAYAWAY]} empty="" />);

        expect(screen.getByText('Atrasado')).toHaveClass('badge-error');
    });

    it('un comprobante en revisión es amarillo en la ficha del cliente y en la bandeja', () => {
        render(<CustomerReceipts receipts={[RECEIPT]} />);
        expect(screen.getByText('En revisión')).toHaveClass('badge-warning');

        const status = inboxColumns(Date.now()).find((column) => column.id === 'status');
        render(<>{status?.cell(inboxRow())}</>);
        expect(screen.getAllByText('En revisión')[1]).toHaveClass('badge-warning');
    });

    it('una factura en revisión usa el mismo amarillo que un comprobante en revisión', () => {
        expect(INVOICE_TONES.under_review).toBe(RECEIPT_TONES.pending_review);
        expect(toneOf(LAYAWAY_TONES, 'algo-nuevo')).toBe('neutral');
    });
});
