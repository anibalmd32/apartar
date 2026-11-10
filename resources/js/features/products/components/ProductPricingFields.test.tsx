import { ProductPricingFields } from '@/features/products/components/ProductPricingFields';
import { type ProductFormData } from '@/features/products/hooks/useProductForm';
import { centsUsd } from '@/lib/money';
import { type RateSourceChoices } from '@/types/exchange';
import { type FinancingPlanView } from '@/types/product';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    usePage,
    router: { reload: vi.fn() },
    Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
}));

const RATES: RateSourceChoices = {
    options: [
        { value: '', label: 'La del catálogo o, si no tiene, la del negocio (BCV)' },
        { value: 'bcv', label: 'BCV' },
        { value: 'parallel', label: 'Paralelo' },
        { value: 'manual', label: 'Tasa propia' },
    ],
    businessSource: 'bcv',
    hasManualRate: false,
};

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
    return render(<ProductPricingFields data={DATA} setData={vi.fn() as never} errors={{}} rateSources={RATES} plan={view} />);
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

function cashPlan(): FinancingPlanView {
    return {
        isCashSale: true,
        downPayment: money(15_000, '$150,00'),
        installmentsCount: 0,
        frequencyDays: 0,
        installmentAmount: null,
        lastInstallmentAmount: null,
        hasUnevenLastInstallment: false,
        financedAmount: money(0, '$0,00'),
        total: money(15_000, '$150,00'),
        compareAtPrice: null,
        savingsPercent: null,
    };
}

it('con la inicial igual al precio sugiere en naranja marcarlo al contado y la casilla parpadea', () => {
    renderFields(cashPlan());

    expect(screen.getByRole('alert')).toHaveTextContent('Si lo cobras completo de una vez, marca «Venta al contado».');
    expect(screen.getByRole('alert')).toHaveClass('alert-warning');
    expect(screen.getByRole('checkbox', { name: /Venta al contado/ }).closest('label')).toHaveClass('animate-pulse', 'ring-warning');
    expect(screen.getByText('Vista previa del plan').parentElement).toHaveTextContent('Al contado: $150,00 en un solo pago.');
});

it('al marcar «Venta al contado» la inicial pasa a ser el precio; al desmarcarla vuelve a cero', () => {
    const setData = vi.fn();
    render(<ProductPricingFields data={DATA} setData={setData as never} errors={{}} rateSources={RATES} plan={plan(3_000)} />);

    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.getByRole('checkbox', { name: /Venta al contado/ }).closest('label')).not.toHaveClass('animate-pulse');

    screen.getByRole('checkbox', { name: /Venta al contado/ }).click();

    const update = setData.mock.calls[0]?.[0] as (previous: ProductFormData) => ProductFormData;
    expect(update(DATA)).toMatchObject({ cash_sale: true, down_payment: '150.00' });
    expect(update({ ...DATA, cash_sale: true })).toMatchObject({ cash_sale: true });

    const unset = vi.fn();
    render(
        <ProductPricingFields
            data={{ ...DATA, cash_sale: true, down_payment: '150.00' }}
            setData={unset as never}
            errors={{}}
            rateSources={RATES}
            plan={cashPlan()}
        />,
    );
    screen.getAllByRole('checkbox', { name: /Venta al contado/ })[1]!.click();

    const revert = unset.mock.calls[0]?.[0] as (previous: ProductFormData) => ProductFormData;
    expect(revert({ ...DATA, cash_sale: true, down_payment: '150.00' })).toMatchObject({ cash_sale: false, down_payment: '0,00' });
});

it('una venta al contado esconde la inicial, las cuotas y la frecuencia, y no sugiere nada', () => {
    render(
        <ProductPricingFields
            data={{ ...DATA, cash_sale: true, down_payment: '150.00' }}
            setData={vi.fn() as never}
            errors={{}}
            rateSources={RATES}
            plan={cashPlan()}
        />,
    );

    expect(screen.getByRole('checkbox', { name: /Venta al contado/ })).toBeChecked();
    expect(screen.getByLabelText('Cuota inicial').closest('.hidden')).not.toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
});

it('la tasa hereda la del catálogo o el negocio y avisa si eligen tasa propia sin haberla escrito', () => {
    const setData = vi.fn();
    const { rerender } = render(
        <ProductPricingFields data={{ ...DATA, rate_source: '' }} setData={setData as never} errors={{}} rateSources={RATES} plan={plan(0)} />,
    );

    const select = screen.getByLabelText('Tasa para los bolívares');
    expect(select).toHaveValue('');
    expect(screen.getByRole('option', { name: 'La del catálogo o, si no tiene, la del negocio (BCV)' })).toBeInTheDocument();

    fireEvent.change(select, { target: { value: 'parallel' } });
    expect(setData).toHaveBeenCalledWith('rate_source', 'parallel');

    rerender(
        <ProductPricingFields data={{ ...DATA, rate_source: 'manual' }} setData={setData as never} errors={{}} rateSources={RATES} plan={plan(0)} />,
    );
    expect(screen.getByText(/Todavía no escribiste tu tasa propia/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Escríbela en Ajustes' })).toBeInTheDocument();
});
