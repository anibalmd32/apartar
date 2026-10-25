import { DashboardWarnings } from '@/features/dashboard/components/DashboardWarnings';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import { planLimitsOf } from '@/features/plan/hooks/usePlanLimits';
import { planState, usage } from '@/test/plan-fixtures';
import { type PlanSharedProps } from '@/types/plan';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

let shared: { -readonly [Key in keyof PlanSharedProps]?: PlanSharedProps[Key] } & { teamMember?: { capabilities: string[] } } = {};

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/backoffice', props: { errors: {}, ...shared } }),
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

beforeEach(() => {
    shared = {
        planState: planState(),
        planLimit: null,
        planFeature: null,
        planBlocked: null,
        teamMember: { capabilities: ['subscription'] },
    } as typeof shared;
});

it('no avisa por debajo del 80 %', () => {
    const { container } = render(<LimitBanner metric="products" />);

    expect(container).toBeEmptyDOMElement();
});

it('avisa al 80 % con el plan que da más sitio', () => {
    shared.planState = planState({
        usage: { ...planState().usage, products: usage(24, 30) },
        upgrades: { products: { planCode: 'pro', planName: 'Pro' } },
    });

    render(<LimitBanner metric="products" />);

    expect(screen.getByRole('alert')).toHaveTextContent('Llevas 24 de 30 productos de tu plan Free. Pro te da más sitio.');
    expect(screen.getByRole('link', { name: 'Ver planes' })).toHaveAttribute('href', '/backoffice/suscripcion');
});

it('en el techo dice que no cabe uno más', () => {
    shared.planState = planState({
        usage: { ...planState().usage, catalogs: usage(1, 1) },
        upgrades: { catalogs: { planCode: 'pro', planName: 'Pro' } },
    });

    render(<LimitBanner metric="catalogs" />);

    expect(screen.getByRole('alert')).toHaveTextContent(
        'Llegaste al techo de tu plan Free: 1 de 1 catálogos publicados. No cabe uno más. Pro te da más sitio.',
    );
});

it('en el techo de productos ofrece además archivarlos', () => {
    shared.planState = planState({ usage: { ...planState().usage, products: usage(30, 30) } });

    render(<LimitBanner metric="products" />);

    expect(screen.getByRole('link', { name: 'Archivar productos' })).toHaveAttribute('href', '/backoffice/productos');
    expect(screen.getByRole('link', { name: 'Ver planes' })).toBeInTheDocument();
});

it('un techo ilimitado no avisa nunca', () => {
    shared.planState = planState({ usage: { ...planState().usage, products: usage(9_000, null) } });

    const { container } = render(<LimitBanner metric="products" />);

    expect(container).toBeEmptyDOMElement();
});

it('enseña la función que falta y el plan que la incluye', () => {
    render(<UpgradePrompt feature="excel_export" />);

    expect(screen.getByText(/Exportación a Excel: no está en tu plan, la incluye Pro\./)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Ver planes' })).toHaveAttribute('href', '/backoffice/suscripcion');
});

it('calla si el plan ya incluye la función', () => {
    shared.planState = planState({ features: ['excel_export'] });

    const { container } = render(<UpgradePrompt feature="excel_export" />);

    expect(container).toBeEmptyDOMElement();
});

it('pinta el último rechazo por cupo con su sugerencia', () => {
    shared.planLimit = {
        message: 'Tu plan Free permite 1 catálogo publicado. Pro permite 10 catálogos publicados.',
        limit: {
            metric: 'catalogs',
            used: 1,
            limit: 1,
            minimumPlan: 'pro',
            minimumPlanName: 'Pro',
            suggestion: 'También puedes despublicar otro catálogo para liberar su sitio.',
        },
        upgradeUrl: '/backoffice/suscripcion',
    };

    render(<UpgradePrompt />);

    expect(screen.getByRole('alert')).toHaveTextContent(
        'Tu plan Free permite 1 catálogo publicado. Pro permite 10 catálogos publicados. También puedes despublicar otro catálogo para liberar su sitio.',
    );
});

it('sin rechazos, el layout no pinta nada', () => {
    const { container } = render(<UpgradePrompt />);

    expect(container).toBeEmptyDOMElement();
});

it('el aviso de ventas perdidas dice qué techo las rechaza', () => {
    render(<DashboardWarnings warnings={[{ kind: 'plan_limit_blocking_sales', metric: 'active_layaways' }]} />);

    expect(screen.getByRole('region', { name: 'Avisos' })).toHaveTextContent(
        'Esta semana tu plan rechazó apartados de tus clientes: llegaste al techo de apartados activos.',
    );
    expect(screen.getByRole('link', { name: 'Ver planes' })).toHaveAttribute('href', '/backoffice/suscripcion');
});

it('a quien no gobierna el plan le dice a quién pedírselo', () => {
    shared.teamMember = { capabilities: ['products'] };

    render(<UpgradePrompt feature="excel_export" />);

    expect(screen.queryByRole('link', { name: 'Ver planes' })).not.toBeInTheDocument();
    expect(screen.getByText('Pídele al dueño del negocio que revise el plan.')).toBeInTheDocument();
});

it('el aviso empieza exactamente en el 80 %', () => {
    shared.planState = planState({ usage: { ...planState().usage, products: usage(23, 30) } });
    const { container, rerender } = render(<LimitBanner metric="products" />);
    expect(container).toBeEmptyDOMElement();

    shared.planState = planState({ usage: { ...planState().usage, products: usage(8, 10) } });
    rerender(<LimitBanner metric="products" />);
    expect(screen.getByRole('alert')).toHaveTextContent('Llevas 8 de 10 productos');
});

it('pinta el rechazo por suspensión y el de una función', () => {
    shared.planBlocked = { message: 'Tu suscripción está suspendida.', upgradeUrl: '/backoffice/suscripcion' };
    const { rerender } = render(<UpgradePrompt />);
    expect(screen.getByRole('alert')).toHaveTextContent('Tu suscripción está suspendida.');

    shared.planBlocked = null;
    shared.planFeature = {
        message: 'Tu plan Free no incluye: exportación a Excel. Pro sí la incluye.',
        feature: { feature: 'excel_export', minimumPlan: 'pro', minimumPlanName: 'Pro' },
        upgradeUrl: '/backoffice/suscripcion',
    };
    rerender(<UpgradePrompt />);
    expect(screen.getByRole('alert')).toHaveTextContent('Tu plan Free no incluye: exportación a Excel.');
});

it('sin estado del plan no niega ninguna función', () => {
    const limits = planLimitsOf(null);

    expect(limits.hasFeature('excel_export')).toBe(true);
    expect(limits.isNearLimit('products')).toBe(false);
    expect(limits.metricLabel('products')).toBe('products');
});
