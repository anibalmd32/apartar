import { FREE_PLAN_SUMMARY, PlanCard } from '@/features/marketing/components/PlanCard';
import { type PricingPlan } from '@/types/marketing';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => ({
    Link: (await import('@/test/fakes')).FakeLink,
}));

beforeEach(() => {
    vi.stubGlobal('route', (name: string, params?: Record<string, string>) => (params?.plan ? `/${name}?plan=${params.plan}` : `/${name}`));
});

const storage = { key: 'storage_mb', label: 'GB de almacenamiento', limit: 5 };

const free: PricingPlan = { code: 'free', name: 'Gratis', price: '$0', period: 'para siempre', isFree: true, limits: [], features: [] };
const pro: PricingPlan = {
    code: 'pro',
    name: 'Pro',
    price: '$15',
    period: 'al mes',
    isFree: false,
    limits: [storage],
    features: ['Exportación a Excel', 'Analítica completa'],
};
const business: PricingPlan = { ...pro, code: 'business', name: 'Negocio', price: '$39', features: [...pro.features, 'Dominio propio'] };

function card(plan: PricingPlan, previous?: PricingPlan) {
    render(
        <ul>
            <PlanCard plan={plan} previous={previous} />
        </ul>,
    );
}

it('cada plan de pago tiene su botón para empezar con ese plan', () => {
    card(pro, free);

    const cta = screen.getByRole('link', { name: 'Empezar con Pro' });

    expect(cta).toHaveAttribute('href', '/register?plan=pro');
    expect(cta).toHaveClass('pz-btn');
});

it('el plan gratis dice «Gratis» una sola vez y muestra el precio en cifra', () => {
    card(free);

    expect(screen.getAllByText(/Gratis/)).toHaveLength(1);
    expect(screen.getByText('$0')).toBeInTheDocument();
    expect(screen.getByText('para siempre')).toBeInTheDocument();
});

it('el plan gratis no se presenta como uno que no maneja pagos', () => {
    card(free);

    expect(screen.queryByText(/Sin funciones de pago/)).not.toBeInTheDocument();
    expect(screen.getByText(FREE_PLAN_SUMMARY)).toBeInTheDocument();
});

it('un plan superior resume lo heredado y solo lista lo que agrega', () => {
    card(business, pro);

    expect(screen.getByText('Todo lo de Pro, más:')).toBeInTheDocument();
    expect(screen.getByText('Dominio propio')).toBeInTheDocument();
    expect(screen.queryByText('Exportación a Excel')).not.toBeInTheDocument();
    expect(screen.getByText('al mes')).toBeInTheDocument();
});
