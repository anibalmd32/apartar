import { FILTER_DEBOUNCE_MS } from '@/features/data-table/hooks/useDebouncedFilter';
import CustomerIndex from '@/pages/backoffice/customers/Index';
import CustomerShow from '@/pages/backoffice/customers/Show';
import { directoryProps, directoryRow, profileProps } from '@/test/t065-fixtures';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { routerGet, post } = vi.hoisted(() => ({ routerGet: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        usePage: () => ({ url: '/backoffice/clientes', props: { errors: {}, currency: { display: 'usd_primary', rate: { rate: null } } } }),
        router: { get: routerGet, post: vi.fn(), on: () => () => undefined },
        Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
            <a href={href} className={className}>
                {children}
            </a>
        ),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState(initial);
            const setData = (key: keyof T | T, value?: unknown) =>
                setState((previous) => (typeof key === 'object' ? (key as T) : { ...previous, [key]: value }));

            return { data, setData, errors: {}, processing: false, post: (url: string) => post(url, data) };
        },
    };
});

vi.mock('@/layouts/backoffice-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal('fetch', vi.fn());
    routerGet.mockReset();
    post.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('el directorio pinta los agregados y destaca la mora', () => {
    render(
        <CustomerIndex
            {...directoryProps([
                directoryRow({ hasLinkedAccount: true }),
                directoryRow({ id: 8, name: 'Luis Rojas', overdueInstallments: 1, inArrears: false }),
            ])}
        />,
    );

    const row = within(screen.getAllByRole('row')[1]!);
    expect(row.getByRole('link', { name: 'María González' })).toHaveAttribute('href', '/backoffice/clientes/7');
    expect(row.getByText('V-12.345.678')).toBeInTheDocument();
    expect(row.getByText('Cuenta vinculada')).toBeInTheDocument();
    expect(row.getByText(/1\s+vencida/)).toHaveClass('badge-error');
    expect(row.getByText('En mora')).toHaveClass('badge-error');

    expect(within(screen.getAllByRole('row')[2]!).queryByText('En mora')).not.toBeInTheDocument();
});

it('el directorio filtra por mora y busca tras la pausa', async () => {
    render(<CustomerIndex {...directoryProps([directoryRow()])} />);

    fireEvent.click(screen.getByRole('button', { name: 'En mora' }));
    expect(routerGet.mock.calls[0]?.[1]).toMatchObject({ overdue: '1' });

    fireEvent.change(screen.getByLabelText('Buscar'), { target: { value: '0414-1234567' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(FILTER_DEBOUNCE_MS + 10);
    });
    expect(routerGet.mock.calls.at(-1)?.[1]).toMatchObject({ q: '0414-1234567' });
});

it('el perfil pinta el contacto y el balance', () => {
    render(<CustomerShow {...profileProps()} />);

    expect(screen.getByRole('link', { name: '0414-1234567' })).toHaveAttribute('href', 'tel:+584141234567');
    expect(screen.getByText('Sin correo')).toBeInTheDocument();
    expect(screen.getByText('$120,00')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Ver más' })).not.toBeInTheDocument();
});

it('el perfil ofrece ver más en las listas cortadas', () => {
    render(<CustomerShow {...profileProps({ lists: { paymentsTotal: 0, receiptsTotal: 23, moreUrl: '/backoffice/clientes/7?mostrar=40' } })} />);

    fireEvent.click(screen.getByRole('button', { name: /^Comprobantes/ }));
    const receipts = within(screen.getByRole('region', { name: 'Comprobantes' }));
    expect(receipts.getByText(/Se muestran los 0 más recientes de 23/)).toBeInTheDocument();
    expect(receipts.getByRole('link', { name: 'Ver más' })).toHaveAttribute('href', '/backoffice/clientes/7?mostrar=40');
    fireEvent.click(screen.getByRole('button', { name: /^Pagos/ }));
    expect(within(screen.getByRole('region', { name: 'Pagos' })).queryByRole('link', { name: 'Ver más' })).not.toBeInTheDocument();
});

it('la fusión se confirma escribiendo el documento de la absorbida', async () => {
    vi.mocked(fetch).mockResolvedValue(
        new Response(
            JSON.stringify({
                candidates: [
                    {
                        id: 9,
                        name: 'María Gonzales',
                        document: 'V-22.222.222',
                        phone: '+584141234567',
                        moves: { layaways: 1, receipts: 2, login_tokens: 0, account_moves: false },
                        blocked: false,
                    },
                    {
                        id: 10,
                        name: 'María G.',
                        document: 'V-33.333.333',
                        phone: '+584141234567',
                        moves: { layaways: 0, receipts: 0, login_tokens: 0, account_moves: false },
                        blocked: true,
                    },
                ],
            }),
        ),
    );
    render(<CustomerShow {...profileProps()} />);

    fireEvent.change(screen.getByLabelText('Buscar la ficha duplicada'), { target: { value: 'maría' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(FILTER_DEBOUNCE_MS + 10);
    });

    expect(vi.mocked(fetch).mock.calls[0]?.[0]).toBe('/backoffice/clientes/7/fusionar/candidatas?q=mar%C3%ADa');
    expect(await screen.findByText('Movería 1 apartados y 2 comprobantes.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /María G\. · V-33\.333\.333/ })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: /María Gonzales · V-22\.222\.222/ }));
    const merge = screen.getByRole('button', { name: 'Fusionar las fichas' });
    expect(merge).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Para confirmar, escribe el documento V-22.222.222'), { target: { value: 'V-22.222.222' } });
    fireEvent.click(merge);

    expect(post).toHaveBeenCalledWith('/backoffice/clientes/7/fusionar', { absorbed: 9, confirmation: 'V-22.222.222' });
});

it('«Todos» quita los filtros rápidos en una sola visita y cada píldora lleva su cifra', () => {
    render(
        <CustomerIndex
            {...directoryProps([], { overdue: '1', due_soon: '1' })}
            filterCounts={{ all: 128, overdue: 7, due_soon: 11, without_active: 34 }}
        />,
    );

    expect(screen.getByRole('button', { name: /En mora\s*7/ })).toHaveAttribute('aria-pressed', 'true');

    fireEvent.click(screen.getByRole('button', { name: /Todos\s*128/ }));

    expect(routerGet).toHaveBeenCalledTimes(1);
    expect(routerGet.mock.calls[0]?.[1]).not.toHaveProperty('overdue');
    expect(routerGet.mock.calls[0]?.[1]).not.toHaveProperty('due_soon');
});

it('«Nuevo cliente» abre el alta y la envía al directorio', () => {
    render(<CustomerIndex {...directoryProps([])} />);

    fireEvent.click(screen.getByRole('button', { name: 'Agregar un cliente' }));

    const dialog = screen.getByRole('dialog', { name: 'Nuevo cliente' });
    fireEvent.change(within(dialog).getByLabelText(/Nombre y apellido/), { target: { value: 'Luis Rojas' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Agregar cliente' }));

    expect(post).toHaveBeenCalledWith('/backoffice/clientes', expect.objectContaining({ full_name: 'Luis Rojas', terms_accepted: false }));
});
