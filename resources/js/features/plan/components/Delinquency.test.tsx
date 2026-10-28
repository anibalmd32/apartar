import { PastDueBanner, type PastDueNotice } from '@/features/plan/components/PastDueBanner';
import { CloseOrganizationCard } from '@/features/tenancy/components/CloseOrganizationCard';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const post = vi.fn();
let pageProps: Record<string, unknown> = {};
let formErrors: Record<string, string> = {};

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        usePage: () => ({ url: '/backoffice', props: pageProps }),
        Link: ({ href, children, className }: { href: string; children: React.ReactNode; className?: string }) => (
            <a href={href} className={className}>
                {children}
            </a>
        ),
        useForm: (initial: Record<string, unknown>) => {
            const [data, setState] = React.useState(initial);

            return {
                data,
                setData: (key: string, value: unknown) => setState((current) => ({ ...current, [key]: value })),
                post: (...args: unknown[]) => post(data, ...args),
                processing: false,
                errors: formErrors,
            };
        },
    };
});

beforeEach(() => {
    post.mockReset();
    pageProps = {};
    formErrors = {};
});

it('dice cuánto se debe y hasta cuándo, y lleva a pagar', () => {
    const notice: PastDueNotice = { amount: '$44,00', deadline: '8 de noviembre', invoicesUrl: '/backoffice/suscripcion/facturas' };
    pageProps = { pastDue: notice };

    render(<PastDueBanner />);

    expect(screen.getByText(/Tienes \$44,00 pendientes/)).toBeInTheDocument();
    expect(screen.getByText(/antes del 8 de noviembre/)).toBeInTheDocument();
    expect(screen.getByText(/tus clientes no notan nada/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Pagar' })).toHaveAttribute('href', '/backoffice/suscripcion/facturas');
});

it('a quien no gestiona la facturación no le dice cuánto ni le da enlace, y sin fecha no anuncia un plazo vencido', () => {
    pageProps = { pastDue: { amount: null, deadline: null, invoicesUrl: null } };

    render(<PastDueBanner />);

    expect(screen.getByText(/Hay un pago pendiente/)).toBeInTheDocument();
    expect(screen.queryByText(/antes del/)).not.toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
});

it('no pinta nada sin impago', () => {
    pageProps = { pastDue: null };

    const { container } = render(<PastDueBanner />);

    expect(container).toBeEmptyDOMElement();
});

it('no deja cerrar sin marcar la casilla y escribir la contraseña, y envía las dos', () => {
    render(<CloseOrganizationCard closeUrl="/backoffice/suscripcion/cerrar-negocio" />);

    const button = screen.getByRole('button', { name: 'Cerrar el negocio' });
    expect(button).toBeDisabled();

    fireEvent.click(screen.getByRole('checkbox'));
    expect(button).toBeDisabled();

    fireEvent.change(screen.getByLabelText(/Tu contraseña/), { target: { value: 'mi-contraseña' } });
    expect(button).toBeEnabled();

    fireEvent.click(button);
    expect(post).toHaveBeenCalledWith(
        { confirmed: true, password: 'mi-contraseña' },
        '/backoffice/suscripcion/cerrar-negocio',
        expect.objectContaining({ preserveScroll: true }),
    );
});

it('pinta la lista de apartados en curso que devuelve el servidor', () => {
    formErrors = { close: 'No puedes cerrar el negocio con apartados en curso. Complétalos o cancélalos primero: AP-1111.' };

    render(<CloseOrganizationCard closeUrl="/x" />);

    expect(screen.getByRole('alert')).toHaveTextContent('AP-1111');
});
