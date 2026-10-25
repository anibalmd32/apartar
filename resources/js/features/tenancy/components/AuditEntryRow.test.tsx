import { AuditEntryRow } from '@/features/tenancy/components/AuditEntryRow';
import { type AuditEntry } from '@/types/audit';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

function entry(changed: AuditEntry['properties']['changed']): AuditEntry {
    return {
        id: 1,
        event: 'installment.rescheduled',
        eventLabel: 'Cuota reprogramada',
        description: 'AP-9QW3E7RT · cuota 3',
        actor: { kind: 'team', name: 'Mariela R.', id: 1 },
        properties: { changed },
        occurredAt: '2026-10-21T13:15:00Z',
    };
}

it('saca el motivo del diff y lo enseña aparte', () => {
    render(
        <ul>
            <AuditEntryRow
                timezone="America/Caracas"
                entry={entry({
                    'installments.3.due_date': { before: '2026-10-21', after: '2026-10-28' },
                    reason: { before: null, after: 'La clienta cobra el día 28.' },
                })}
            />
        </ul>,
    );

    expect(screen.getByText('Motivo')).toBeInTheDocument();
    expect(screen.getByText('La clienta cobra el día 28.')).toBeInTheDocument();
    expect(screen.queryByText('reason')).toBeNull();
    expect(screen.getByText('installments.3.due_date')).toBeInTheDocument();
});

it('con solo el motivo, también se puede ver', () => {
    render(
        <ul>
            <AuditEntryRow timezone="America/Caracas" entry={entry({ reason: { before: null, after: 'Pedido por la dueña.' } })} />
        </ul>,
    );

    expect(screen.queryByText('Sin detalle')).toBeNull();
    expect(screen.getByText('Pedido por la dueña.')).toBeInTheDocument();
});
