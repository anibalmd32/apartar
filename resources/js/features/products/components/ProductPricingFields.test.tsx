import { ProductPricingFields } from '@/features/products/components/ProductPricingFields';
import { type ProductFormData } from '@/features/products/hooks/useProductForm';
import { centsUsd } from '@/lib/money';
import { type FinancingPlanView } from '@/types/product';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ usePage, router: { reload: vi.fn() } }));

const DATA = {
    name: 'Nevera',
    sku: '',
    slug: '',
    description: '',
    price: '150.00',
    compare_at_price: '',
    down_payment: '0.00',
    installments_count: 3,
    frequency_days: 15,
    stock: 1,
    category_id: '',
    category_name: '',
    tag_ids: [],
    tag_names: [],
} as unknown as ProductFormData;

function money(cents: number, usd: string) {
    return { cents: centsUsd(cents), usd, ves: null };
}

function plan(downPayment: number): FinancingPlanView {
    return {
        downPayment: money(downPayment, downPayment === 0 ? '$0,00' : '$30,00'),
        installmentsCount: 3,
        frequencyDays: 15,
        installmentAmount: money(5_000, '$50,00'),
        lastInstallmentAmount: money(5_000, '$50,00'),
        hasUnevenLastInstallment: false,
        financedAmount: money(15_000 - downPayment, '$150,00'),
        total: money(15_000, '$150,00'),
        compareAtPrice: null,
        savingsPercent: null,
    };
}

function renderFields(view: FinancingPlanView | null) {
    return render(<ProductPricingFields data={DATA} setData={vi.fn() as never} errors={{}} plan={view} />);
}

beforeEach(() => {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: null } } });
});

it('con inicial cero avisa de que el apartado arranca activo y la vista previa no pinta inicial', () => {
    renderFields(plan(0));

    expect(screen.getByText('Sin inicial: el apartado arranca activo y solo se paga en cuotas.')).toBeInTheDocument();
    const preview = screen.getByText('Vista previa del plan').parentElement;
    expect(preview).toHaveTextContent('Sin inicial: 3 ×');
    expect(preview).not.toHaveTextContent('de inicial');
    expect(preview).not.toHaveTextContent('Hoy');
});

it('con inicial mantiene la pista de siempre', () => {
    renderFields(plan(3_000));

    expect(screen.getByText('Menor que el precio: lo que queda es lo que se financia.')).toBeInTheDocument();
    expect(screen.queryByText('Sin inicial: el apartado arranca activo y solo se paga en cuotas.')).toBeNull();
});
