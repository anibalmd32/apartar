import { AuditFilters } from '@/features/tenancy/components/AuditFilters';
import { isoDay, periodOf, periodRange } from '@/features/tenancy/lib/audit-period';
import { type AuditEventGroup } from '@/types/audit';
import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ router: { get: vi.fn() } }));

const events: AuditEventGroup[] = [
    { area: 'collections', label: 'Cobranza', events: [{ value: 'receipt.approved', label: 'Comprobante aprobado' }] },
    { area: 'catalog', label: 'Catálogo', events: [{ value: 'product.archived', label: 'Producto archivado' }] },
];

const none = { event: null, actor: null, from: null, to: null };

it('los atajos cuentan hoy como primer día', () => {
    const now = new Date(2026, 9, 24, 15, 0);

    expect(periodRange('today', now)).toEqual({ from: '2026-10-24', to: '2026-10-24' });
    expect(periodRange('last7', now)).toEqual({ from: '2026-10-18', to: '2026-10-24' });
    expect(periodRange('last30', now)).toEqual({ from: '2026-09-25', to: '2026-10-24' });
    expect(periodOf('2026-10-18', '2026-10-24', now)).toBe('last7');
    expect(periodOf('2026-10-01', '2026-10-24', now)).toBeNull();
});

it('el área limita los eventos y suelta el que ya no le pertenece', () => {
    render(<AuditFilters filters={{ ...none, event: 'receipt.approved' }} events={events} actors={[]} />);

    expect(screen.getByLabelText('Área')).toHaveValue('collections');

    fireEvent.change(screen.getByLabelText('Área'), { target: { value: 'catalog' } });

    expect(screen.getByLabelText('Tipo')).toHaveValue('');
    expect(screen.queryByRole('option', { name: 'Cobranza · Comprobante aprobado' })).not.toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Catálogo · Producto archivado' })).toBeInTheDocument();
});

it('«Hoy» rellena desde y hasta, y el área no viaja', () => {
    render(<AuditFilters filters={none} events={events} actors={[]} />);
    const today = isoDay(new Date());

    fireEvent.change(screen.getByLabelText('Área'), { target: { value: 'catalog' } });
    fireEvent.change(screen.getByLabelText('Periodo'), { target: { value: 'today' } });

    expect(document.querySelector('input[name="from"]')).toHaveValue(today);
    expect(document.querySelector('input[name="to"]')).toHaveValue(today);

    fireEvent.click(screen.getByRole('button', { name: 'Filtrar' }));

    expect(router.get).toHaveBeenCalledWith('/backoffice/auditoria', { from: today, to: today }, expect.any(Object));
});
