import { PENDING_RECEIPTS_POLL_MS, SIGNED_URL_REFRESH_MS } from '@/features/receipts/hooks/useReceiptInbox';
import ReceiptInbox from '@/pages/backoffice/receipts/Inbox';
import { INBOX_META as META, inboxProps as props, inboxRow as row } from '@/test/receipt-inbox-fixtures';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { usePage, routerGet, on } = vi.hoisted(() => ({
    usePage: vi.fn(),
    routerGet: vi.fn(),
    on: vi.fn(() => () => undefined),
}));

vi.mock('@inertiajs/react', () => ({
    usePage,
    router: { get: routerGet, on },
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children }: { children: ReactNode }) => {
        const client = new QueryClient();

        return (
            <QueryClientProvider client={client}>
                <main>{children}</main>
            </QueryClientProvider>
        );
    },
}));

function sharing(pendingReceipts: number | null) {
    usePage.mockReturnValue({ url: '/backoffice/comprobantes', props: { errors: {}, teamMember: { pendingReceipts } } });
}

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.setSystemTime(new Date('2026-10-07T12:00:00-04:00'));
    vi.stubGlobal('fetch', vi.fn());
    routerGet.mockReset();
    sharing(2);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('pinta cada fila con sus datos, en el orden en que llega', () => {
    render(<ReceiptInbox {...props([row({ id: 1 }), row({ id: 2, layaway: { code: 'AP-SECOND00', url: '/x', cancelled: false } })])} />);

    const rows = screen.getAllByRole('row').slice(1);
    expect(rows).toHaveLength(2);

    const first = within(rows[0]!);
    expect(first.getByText('hace 3 h')).toBeInTheDocument();
    expect(first.getByRole('link', { name: 'AP-7K3M9QRT' })).toHaveAttribute('href', '/backoffice/apartados/AP-7K3M9QRT');
    expect(first.getByText('María González')).toBeInTheDocument();
    expect(first.getByText('V-12.345.678')).toBeInTheDocument();
    expect(first.getByText('Mercantil · Ref. 00042')).toBeInTheDocument();
    expect(first.getByText('Cuenta desactivada')).toBeInTheDocument();
    expect(first.getByText('Bs. 1.275,50')).toBeInTheDocument();
    expect(first.getByText('06/10/2026')).toBeInTheDocument();
    expect(first.getByText('En revisión')).toBeInTheDocument();
    expect(within(rows[1]!).getByText('AP-SECOND00')).toBeInTheDocument();
});

it('marca el atrasado que dice el servidor, y solo ese', () => {
    render(<ReceiptInbox {...props([row({ id: 1, overdue: true }), row({ id: 2 })])} />);

    const rows = screen.getAllByRole('row').slice(1);
    expect(within(rows[0]!).getByText('Atrasado')).toBeInTheDocument();
    expect(within(rows[1]!).queryByText('Atrasado')).not.toBeInTheDocument();
});

it('enseña la miniatura de una imagen y un icono para un PDF, sin renderizarlo', () => {
    const { container } = render(
        <ReceiptInbox {...props([row({ id: 1 }), row({ id: 2, file: { url: '/backoffice/comprobantes/2/archivo?signature=def', kind: 'pdf' } })])} />,
    );

    const table = within(screen.getByRole('table'));
    const images = container.querySelector('table')!.querySelectorAll('img');
    expect(images).toHaveLength(1);
    expect(images[0]).toHaveAttribute('src', '/backoffice/comprobantes/1/archivo?signature=abc');
    expect(table.getByText('PDF').closest('a')).toHaveAttribute('href', '/backoffice/comprobantes/2/archivo?signature=def');
});

it('marca el comprobante de un apartado cancelado', () => {
    render(
        <ReceiptInbox
            {...props([row({ status: 'rejected', statusLabel: 'Rechazado', layaway: { code: 'AP-7K3M9QRT', url: '/x', cancelled: true } })])}
        />,
    );

    const [first] = screen.getAllByRole('row').slice(1);
    expect(within(first!).getByText('Apartado cancelado')).toBeInTheDocument();
    expect(within(first!).getByText('Rechazado')).toBeInTheDocument();
});

it('cambiar un filtro visita la misma URL con la query string, pidiendo también el badge', () => {
    render(<ReceiptInbox {...props([row()])} />);

    fireEvent.click(within(screen.getByRole('group', { name: 'Estado' })).getByRole('button', { name: 'Aprobados' }));
    fireEvent.click(screen.getByLabelText('Incluir los de apartados cancelados'));

    expect(routerGet).toHaveBeenNthCalledWith(
        1,
        expect.any(String),
        { status: 'approved' },
        expect.objectContaining({ only: ['rows', 'meta', 'now', 'teamMember', 'newestPending'] }),
    );
    expect(routerGet).toHaveBeenNthCalledWith(2, expect.any(String), { cancelled: '1' }, expect.anything());
});

it('avisa de un comprobante más nuevo aunque la cifra no haya subido, y lleva a lo más reciente', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ pending: 2, newest: 41 }), { status: 200 }));
    render(<ReceiptInbox {...props([row()])} />);

    expect(screen.queryByText(/comprobantes nuevos/)).not.toBeInTheDocument();

    await act(async () => {
        await vi.advanceTimersByTimeAsync(PENDING_RECEIPTS_POLL_MS + 10);
    });

    expect(screen.getByText('Llegaron comprobantes nuevos.')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Ver los más recientes' }));
    expect(routerGet).toHaveBeenCalledWith(
        expect.any(String),
        { sort: '-submitted' },
        expect.objectContaining({ only: ['rows', 'meta', 'now', 'teamMember', 'newestPending'] }),
    );
});

it('una cifra mayor sin un pendiente más nuevo no es una llegada', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ pending: 9, newest: 40 }), { status: 200 }));
    render(<ReceiptInbox {...props([row()])} />);

    await act(async () => {
        await vi.advanceTimersByTimeAsync(PENDING_RECEIPTS_POLL_MS + 10);
    });

    expect(screen.queryByText(/comprobantes nuevos/)).not.toBeInTheDocument();
});

it('se recarga antes de que caduquen las URL de las miniaturas', async () => {
    render(<ReceiptInbox {...props([row()], { ...META, page: 2, filters: { ...META.filters, status: 'pending_review' } })} />);

    await act(async () => {
        await vi.advanceTimersByTimeAsync(SIGNED_URL_REFRESH_MS - 1000);
    });
    expect(routerGet).not.toHaveBeenCalled();

    await act(async () => {
        await vi.advanceTimersByTimeAsync(2000);
    });
    expect(routerGet).toHaveBeenCalledWith(expect.any(String), { page: '2', status: 'pending_review' }, expect.anything());
    expect(SIGNED_URL_REFRESH_MS).toBeLessThan(15 * 60_000);
});

it('una página más allá de la última no dice que nunca llegó nada', () => {
    render(<ReceiptInbox {...props([], { ...META, page: 3 })} />);

    expect(screen.getAllByText(/No hay más comprobantes en esta página/).length).toBeGreaterThan(0);
    expect(screen.queryByText(/Todavía no has recibido comprobantes/)).not.toBeInTheDocument();
});

it('sin comprobantes ni filtros, explica qué aparecerá aquí', () => {
    render(<ReceiptInbox {...props([])} />);

    expect(screen.getByText('Aún no hay comprobantes')).toBeInTheDocument();
    expect(screen.getByText(/lo verás aquí para revisarlo/)).toBeInTheDocument();
});

it('cada pestaña de estado lleva su cifra', () => {
    render(<ReceiptInbox {...props([row()])} statusCounts={{ pending_review: 9, approved: 3, rejected: 0 }} />);

    const tabs = within(screen.getByRole('group', { name: 'Estado' }));
    expect(tabs.getByRole('button', { name: /Por revisars*2/ })).toBeInTheDocument();
    expect(tabs.getByRole('button', { name: /Aprobadoss*3/ })).toBeInTheDocument();
    expect(tabs.getByRole('button', { name: 'Rechazados' })).toBeInTheDocument();
});
