import { ExportButton } from '@/features/exports/components/ExportButton';
import ExportsIndex from '@/pages/backoffice/exports/Index';
import { planState } from '@/test/plan-fixtures';
import { type ExportRow } from '@/types/exports';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * Exportar a Excel (`REQ-BOF-043` RB-1, RB-3, SP-2, SP-4, SP-10, `T-070`):
 * el botón deshabilitado con el motivo sin la función, los filtros aplicados
 * que viajan, y la lista con su estado.
 */

const post = vi.fn();
let features: string[] = [];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: '/backoffice/cobranza',
        props: { errors: {}, planState: { ...planState(), features }, teamMember: { capabilities: ['subscription'] } },
    }),
    router: { post: (...args: unknown[]) => post(...args) },
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

beforeEach(() => {
    post.mockReset();
    features = [];
});

/** RB-1 · sin la función, deshabilitado y con el motivo a la vista, no oculto. */
it('se ve deshabilitado con el motivo sin la función', () => {
    render(<ExportButton type="collections" filters={{ preset: 'due_today' }} />);

    expect(screen.getByRole('button', { name: 'Exportar a Excel' })).toBeDisabled();
    expect(screen.getByText(/Exportación a Excel: no está en tu plan, la incluye Pro/)).toBeInTheDocument();
});

/** RB-3 · manda solo los filtros aplicados, como texto: lo que la tabla ignoró no viaja. */
it('manda los filtros que la pantalla aplicó', () => {
    features = ['excel_export'];

    render(<ExportButton type="customers" filters={{ q: 'maría', overdue: '1', from: null, catalog: '' }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Exportar a Excel' }));

    expect(post).toHaveBeenCalledWith('/backoffice/exportaciones', { type: 'customers', filters: { q: 'maría', overdue: '1' } }, expect.any(Object));
});

function row(overrides: Partial<ExportRow> = {}): ExportRow {
    return {
        id: 1,
        type: 'collections',
        typeLabel: 'Cobranza',
        status: 'completed',
        statusLabel: 'Lista',
        requestedAt: '10/09/2026 12:00',
        waitingMinutes: null,
        rowCount: 34,
        fileSize: 2048,
        errorMessage: null,
        expiresAt: '17/09/2026',
        downloadUrl: '/backoffice/exportaciones/1/descargar?signature=x',
        ...overrides,
    };
}

/** La lista: descargar la lista, cuánto espera la que sigue en cola, por qué falló una y la caducada. */
it('enseña cada exportación con lo que se puede hacer', () => {
    render(
        <ExportsIndex
            canExport
            types={[]}
            exports={[
                row(),
                row({ id: 2, status: 'queued', statusLabel: 'En cola', waitingMinutes: 12, downloadUrl: null, rowCount: null, expiresAt: null }),
                row({ id: 3, status: 'failed', statusLabel: 'Falló', errorMessage: 'Tu almacenamiento está lleno.', downloadUrl: null }),
                row({ id: 4, status: 'expired', statusLabel: 'Caducada', downloadUrl: null }),
            ]}
        />,
    );

    expect(screen.getAllByRole('link', { name: 'Descargar' })).toHaveLength(1);
    expect(screen.getByText(/34 filas · disponible hasta el 17\/09\/2026/)).toBeInTheDocument();
    expect(screen.getByText(/esperando desde hace 12 min/)).toBeInTheDocument();
    expect(screen.getByText('Tu almacenamiento está lleno.')).toBeInTheDocument();
    expect(screen.getByText(/se borró a los siete días/)).toBeInTheDocument();
});
