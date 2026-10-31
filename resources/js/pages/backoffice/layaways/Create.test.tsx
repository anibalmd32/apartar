import { AppRoot } from '@/app-root';
import { centsUsd } from '@/lib/money';
import LayawayCreate, { PLAN_DEBOUNCE_MS } from '@/pages/backoffice/layaways/Create';
import { MANUAL_PAYMENT_OPTIONS } from '@/test/manual-payment-fixtures';
import { type BackofficeLayawayCreateProps } from '@/types/layaway-backoffice';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { post, reload, usePage } = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn(), usePage: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const { useRef, useState } = await import('react');

    return {
        usePage,
        router: { reload },
        Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState(initial);
            const transformer = useRef((values: T) => values);
            const setData = (key: keyof T | ((previous: T) => T), value?: unknown) =>
                setState((previous) => (typeof key === 'function' ? key(previous) : { ...previous, [key]: value }));

            return {
                data,
                setData,
                errors: {},
                processing: false,
                transform: (callback: (values: T) => T) => {
                    transformer.current = callback;
                },
                post: (url: string) => post(url, transformer.current(data)),
            };
        },
    };
});

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

const money = (usd: string, cents: number) => ({ cents: centsUsd(cents), usd, ves: null });

function props(overrides: Partial<BackofficeLayawayCreateProps> = {}): BackofficeLayawayCreateProps {
    const { storeUrl: _store, previewUrl: _preview, ...payment } = MANUAL_PAYMENT_OPTIONS;

    return {
        products: [{ id: 7, name: 'Nevera Mabe', sku: 'NEV-14', stock: 3, maxQuantity: 3 }],
        selection: { product: 7, quantity: 1 },
        plan: {
            productName: 'Nevera Mabe',
            quantity: 1,
            total: money('$200,00', 20_000),
            downPayment: money('$50,00', 5_000),
            requiresDownPayment: true,
            financedAmount: money('$150,00', 15_000),
            installmentsCount: 5,
            frequencyDays: 15,
            installmentAmount: money('$30,00', 3_000),
            lastInstallmentAmount: null,
            installments: [],
        },
        customer: { id: 4, name: 'María González', detail: 'V12345678 · +584141234567' },
        window: { defaultHours: 72, maxHours: 2160, maxDays: 90 },
        payment,
        urls: {
            store: '/backoffice/apartados',
            customers: '/backoffice/apartados/nuevo/clientes',
            paymentPreview: '/backoffice/apartados/nuevo/vista-previa-pago',
        },
        ...overrides,
    };
}

function renderPage(overrides: Partial<BackofficeLayawayCreateProps> = {}) {
    return render(
        <AppRoot>
            <LayawayCreate {...props(overrides)} />
        </AppRoot>,
    );
}

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal('fetch', vi.fn());
    post.mockReset();
    reload.mockReset();
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', rate: { rate: null } }, errors: {} } });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('muestra el plan del producto y crea el apartado para el cliente elegido', () => {
    renderPage();

    const plan = screen.getByRole('region', { name: 'Plan del apartado' });
    expect(plan).toHaveTextContent('$200,00');
    expect(plan).toHaveTextContent('$50,00');
    expect(plan).toHaveTextContent('5 × $30,00');
    expect(plan).toHaveTextContent('cada 15 días');
    expect(plan).toHaveTextContent('Esperando inicial');
    expect(screen.getByText('María González')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Crear el apartado' }));

    expect(post).toHaveBeenCalledWith(
        '/backoffice/apartados',
        expect.objectContaining({ product: '7', quantity: 1, customer_mode: 'existing', customer: '4', window: 'default', with_payment: false }),
    );
});

it('no deja crear sin cliente y pide el consentimiento declarado para uno nuevo', () => {
    renderPage({ customer: null });
    const submit = screen.getByRole('button', { name: 'Crear el apartado' });

    expect(submit).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Cliente nuevo' }));
    fireEvent.change(screen.getByLabelText('Nombre y apellido'), { target: { value: 'Pedro Rojas' } });
    expect(submit).toBeDisabled();

    fireEvent.click(screen.getByLabelText('El cliente aceptó las condiciones del apartado'));
    expect(submit).toBeEnabled();
});

it('recarga solo el plan cuando cambia la cantidad', () => {
    renderPage();

    fireEvent.click(screen.getByRole('button', { name: 'Añadir una unidad' }));
    act(() => vi.advanceTimersByTime(PLAN_DEBOUNCE_MS));

    expect(reload).toHaveBeenCalledWith({ only: ['plan', 'selection'], data: { producto: '7', cantidad: 2 } });
});

it('elige un plazo propio o sin vencimiento para la inicial', () => {
    renderPage();
    const submit = screen.getByRole('button', { name: 'Crear el apartado' });

    fireEvent.change(screen.getByLabelText('Vence'), { target: { value: 'days' } });
    expect(submit).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Días'), { target: { value: '3' } });
    expect(screen.getByText('Vence en 3 días si no se abona nada de la inicial.')).toBeInTheDocument();
    expect(submit).toBeEnabled();

    fireEvent.change(screen.getByLabelText('Vence'), { target: { value: 'none' } });
    expect(screen.getByText('La inicial no vence: el apartado espera hasta que la registres.')).toBeInTheDocument();
});

it('sin inicial no pregunta el plazo y nace activo', () => {
    const plan = { ...props().plan!, requiresDownPayment: false, downPayment: money('$0,00', 0) };
    renderPage({ plan });

    expect(screen.queryByLabelText('Vence')).not.toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Plan del apartado' })).toHaveTextContent('Activo, sin inicial');
});

it('al cambiar a un producto sin inicial olvida el plazo a medias', () => {
    const client = new QueryClient();
    const view = render(
        <QueryClientProvider client={client}>
            <LayawayCreate {...props()} />
        </QueryClientProvider>,
    );
    const submit = screen.getByRole('button', { name: 'Crear el apartado' });

    fireEvent.change(screen.getByLabelText('Vence'), { target: { value: 'hours' } });
    expect(submit).toBeDisabled();

    const plan = { ...props().plan!, requiresDownPayment: false, downPayment: money('$0,00', 0) };
    view.rerender(
        <QueryClientProvider client={client}>
            <LayawayCreate {...props({ plan })} />
        </QueryClientProvider>,
    );

    expect(screen.queryByLabelText('Vence')).not.toBeInTheDocument();
    expect(submit).toBeEnabled();

    fireEvent.click(submit);

    expect(post).toHaveBeenCalledWith('/backoffice/apartados', expect.objectContaining({ window: 'default', window_value: '' }));
});

it('al registrar un pago pide sus datos antes de crear', () => {
    renderPage();
    const submit = screen.getByRole('button', { name: 'Crear el apartado' });

    fireEvent.click(screen.getByLabelText('Registrar un pago ahora'));

    expect(screen.getByLabelText('Método')).toBeInTheDocument();
    expect(submit).toBeDisabled();
});
