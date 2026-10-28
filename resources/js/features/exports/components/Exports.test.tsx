import { ExportButton } from '@/features/exports/components/ExportButton';
import ExportsIndex from '@/pages/backoffice/exports/Index';
import { planState } from '@/test/plan-fixtures';
import { type ExportRow } from '@/types/exports';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

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

it('se ve deshabilitado con el motivo sin la función', () => {
    render(<ExportButton type="collections" filters={{ preset: 'due_today' }} />);

    expect(screen.getByRole('button', { name: 'Exportar a Excel' })).toBeDisabled();
    expect(screen.queryByRole('tooltip')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Por qué no está disponible' }));

    expect(screen.getByRole('tooltip')).toHaveTextContent('Exportación a Excel no está incluida en tu plan; la incluye Pro.');
});

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
        requestedIso: '2026-09-10T16:00:00Z',
        expiresIso: '2026-09-17T16:00:00Z',
        retryFilters: null,
        waitingMinutes: null,
        rowCount: 34,
        fileSize: 2048,
        errorMessage: null,
        expiresAt: '17/09/2026',
        downloadUrl: '/backoffice/exportaciones/1/descargar?signature=x',
        ...overrides,
    };
}

it('enseña cada exportación con lo que se puede hacer', () => {
    render(
        <ExportsIndex
            canExport
            types={[]}
            exports={[
                row(),
                row({ id: 2, status: 'queued', statusLabel: 'En cola', waitingMinutes: 12, downloadUrl: null, rowCount: null, expiresAt: null }),
                row({
                    id: 3,
                    status: 'failed',
                    statusLabel: 'Falló',
                    errorMessage: 'Tu almacenamiento está lleno.',
                    downloadUrl: null,
                    retryFilters: { preset: 'due_today' },
                }),
                row({ id: 4, status: 'expired', statusLabel: 'Caducada', downloadUrl: null }),
            ]}
        />,
    );

    expect(screen.getAllByRole('link', { name: 'Descargar' })).toHaveLength(1);
    expect(screen.getAllByText('34').length).toBeGreaterThan(0);
    expect(screen.getAllByText('2 KB').length).toBeGreaterThan(0);
    expect(screen.getByText('hasta 17/09')).toBeInTheDocument();
    expect(screen.getByText('hace 12 min')).toBeInTheDocument();
    expect(screen.getByText('Tu almacenamiento está lleno.')).toBeInTheDocument();
    expect(screen.getByText('Se borró a los siete días')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }));

    expect(post).toHaveBeenLastCalledWith(
        '/backoffice/exportaciones',
        { type: 'collections', filters: { preset: 'due_today' } },
        { preserveScroll: true },
    );
});

it('exporta desde la tarjeta los tipos permitidos', () => {
    render(
        <ExportsIndex
            canExport
            types={[
                { value: 'collections', label: 'Cobranza', allowed: true },
                { value: 'inventory', label: 'Inventario', allowed: false },
            ]}
            exports={[]}
        />,
    );

    expect(screen.queryByRole('button', { name: 'Exportar Inventario' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Exportar Cobranza' }));

    expect(post).toHaveBeenCalledWith('/backoffice/exportaciones', { type: 'collections', filters: {} }, { preserveScroll: true });
});
