import { PlanOptionCard } from '@/features/plan/components/PlanOptionCard';
import { ScheduledChangeNotice } from '@/features/plan/components/ScheduledChangeNotice';
import { describeExcess, formatPlanDate } from '@/lib/plan-change';
import { planOption, planState } from '@/test/plan-fixtures';
import { type PlanChangePreview } from '@/types/plan';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const post = vi.fn();
let lastData: Record<string, unknown> = {};

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        usePage: () => ({
            url: '/backoffice/suscripcion',
            props: { errors: {}, planState: planState(), teamMember: { capabilities: ['subscription'] } },
        }),
        useForm: (initial: Record<string, unknown>) => {
            const [data, setState] = React.useState(initial);
            lastData = data;

            return {
                data,
                setData: (key: string, value: unknown) => setState((current) => ({ ...current, [key]: value })),
                post: (...args: unknown[]) => post(data, ...args),
                processing: false,
                errors: {},
            };
        },
    };
});

beforeEach(() => {
    post.mockReset();
    lastData = {};
});

const CARACAS = 'America/Caracas';

function downgradePreview(overrides: Partial<PlanChangePreview> = {}): PlanChangePreview {
    return {
        direction: 'downgrade',
        effectiveAt: '2026-11-09T12:00:00-04:00',
        unpublishAt: '2026-11-16T12:00:00-04:00',
        excesses: [
            { metric: 'catalogs', used: 8, limit: 1, excess: 7, consequence: 'unpublish' },
            { metric: 'products', used: 240, limit: 30, excess: 210, consequence: 'block_creation' },
        ],
        productsPerCatalog: { limit: 20, catalogs: [{ name: 'Temporada', slug: 'temporada', products: 35 }], count: 1 },
        lostFeatures: ['excel_export'],
        gainedFeatures: [],
        ...overrides,
    };
}

it('dice qué quedará por encima y qué pasará con ello', () => {
    const [catalogs, products] = downgradePreview().excesses;

    expect(describeExcess(catalogs!, 'catálogos publicados', 'Free', '2026-11-16T12:00:00-04:00', CARACAS)).toBe(
        'Tienes 8 catálogos publicados; en Free quedarán 1 y 7 se despublicarán a partir del 16/11/2026, conservando los más antiguos.',
    );
    expect(describeExcess(catalogs!, 'catálogos publicados', 'Free', null)).toBe(
        'Tienes 8 catálogos publicados; en Free quedarán 1 y 7 se despublicarán unos días después del cambio, conservando los más antiguos.',
    );
    expect(describeExcess(products!, 'productos', 'Free', null)).toBe(
        'Tienes 240 productos y Free permite 30: no se borra nada, pero no podrás añadir más hasta bajar de 30.',
    );
});

it('fecha en la zona del negocio y no en la del navegador', () => {
    expect(formatPlanDate('2026-11-09T02:00:00Z', CARACAS)).toBe('08/11/2026');
    expect(formatPlanDate('2026-11-09T02:00:00Z', 'Europe/Madrid')).toBe('09/11/2026');
});

it('no deja bajar con exceso hasta confirmarlo', () => {
    render(<PlanOptionCard option={planOption({ code: 'free', name: 'Free', preview: downgradePreview() })} canChange timeZone={CARACAS} />);

    expect(screen.getByText(/se despublicarán a partir del 16\/11\/2026/)).toBeInTheDocument();
    expect(screen.getByText(/Temporada: 35/)).toBeInTheDocument();
    expect(screen.getByText(/Dejarás de tener: Exportación a Excel/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Bajar a Free' })).toBeDisabled();

    fireEvent.click(screen.getByRole('checkbox', { name: 'Entiendo lo que queda por encima del límite' }));

    expect(screen.getByRole('button', { name: 'Bajar a Free' })).toBeEnabled();

    fireEvent.click(screen.getByRole('button', { name: 'Bajar a Free' }));

    expect(post).toHaveBeenCalledWith({ plan: 'free', confirmed: true }, '/backoffice/suscripcion/cambiar', { preserveScroll: true });
});

it('pide confirmar aunque el único exceso sea el techo por catálogo', () => {
    render(<PlanOptionCard option={planOption({ code: 'free', name: 'Free', preview: downgradePreview({ excesses: [] }) })} canChange />);

    expect(screen.getByRole('button', { name: 'Bajar a Free' })).toBeDisabled();
});

it('baja sin confirmar cuando todo cabe', () => {
    render(
        <PlanOptionCard
            option={planOption({ code: 'pro', name: 'Pro', preview: downgradePreview({ excesses: [], productsPerCatalog: null, lostFeatures: [] }) })}
            canChange
        />,
    );

    expect(screen.queryByRole('checkbox')).toBeNull();
    expect(screen.getByText('Todo lo que tienes cabe en Pro.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Bajar a Pro' })).toBeEnabled();
});

it('sube sin confirmar y manda el plan de la tarjeta', () => {
    render(<PlanOptionCard option={planOption({ code: 'business', name: 'Business' })} canChange />);

    expect(screen.getByText('Exportación a Excel')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Subir a Business' }));

    expect(post).toHaveBeenCalledWith({ plan: 'business', confirmed: false }, '/backoffice/suscripcion/cambiar', { preserveScroll: true });
});

it('no ofrece el botón a quien no puede ni sobre el plan programado', () => {
    const { unmount } = render(<PlanOptionCard option={planOption()} canChange={false} />);

    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.getByText('Pro')).toBeInTheDocument();
    unmount();

    render(<PlanOptionCard option={planOption({ isScheduled: true })} canChange />);

    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.getByText('Programado')).toBeInTheDocument();
});

it('enseña la bajada programada y permite mantener el plan', () => {
    render(
        <ScheduledChangeNotice
            change={{ planCode: 'free', planName: 'Free', effectiveAt: '2026-11-09T12:00:00-04:00', isCancellation: true }}
            currentPlanCode="pro"
            currentPlanName="Pro"
            canChange
            timeZone={CARACAS}
        />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent('Cancelaste Pro: pasarás a Free el 09/11/2026. Hasta entonces conservas todo lo de Pro.');

    fireEvent.click(screen.getByRole('button', { name: 'Mantener Pro' }));

    expect(lastData.plan).toBe('pro');
    expect(post).toHaveBeenCalledWith({ plan: 'pro' }, '/backoffice/suscripcion/cambiar', { preserveScroll: true });
});

it('no llama cancelación a bajar a otro plan de pago ni ofrece revertir a quien no puede', () => {
    render(
        <ScheduledChangeNotice
            change={{ planCode: 'pro', planName: 'Pro', effectiveAt: '2026-11-09T12:00:00-04:00', isCancellation: false }}
            currentPlanCode="business"
            currentPlanName="Business"
            canChange={false}
            timeZone={CARACAS}
        />,
    );

    expect(screen.getByRole('alert')).not.toHaveTextContent('Cancelaste');
    expect(screen.queryByRole('button')).toBeNull();
});

it('subir a un plan de pago pasa por el modal con la factura y el RIF', () => {
    render(
        <PlanOptionCard
            option={planOption({
                code: 'pro',
                name: 'Pro',
                invoicePreview: { periodStart: '2026-10-20T12:00:00Z', periodEnd: '2026-11-20T12:00:00Z', amount: '$15,00', amountVes: 'Bs. 547,80' },
            })}
            canChange
            timeZone={CARACAS}
            needsDocument
        />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Subir a Pro' }));

    expect(post).not.toHaveBeenCalled();
    expect(screen.getByText('$15,00 · Bs. 547,80')).toBeInTheDocument();

    const confirm = screen.getByRole('button', { name: 'Confirmar y ver factura' });
    expect(confirm).toBeDisabled();

    fireEvent.change(screen.getByLabelText('RIF o cédula'), { target: { value: 'J-41234567-8' } });
    fireEvent.click(confirm);

    expect(post).toHaveBeenCalledWith(
        expect.objectContaining({ plan: 'pro', document: 'J-41234567-8' }),
        '/backoffice/suscripcion/cambiar',
        expect.any(Object),
    );
});

it('concuerda el nombre del límite con su número', () => {
    render(
        <PlanOptionCard
            option={planOption({
                code: 'free',
                name: 'Gratis',
                limits: { users: 1, catalogs: 1, products: 30, active_layaways: 15, storage_mb: 200, payment_accounts: 1 },
            })}
            canChange={false}
        />,
    );

    expect(screen.getByText('catálogo publicado')).toBeInTheDocument();
    expect(screen.getByText('usuario')).toBeInTheDocument();
    expect(screen.getByText('productos')).toBeInTheDocument();
});
