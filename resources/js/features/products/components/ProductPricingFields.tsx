import { NumberField } from '@/components/ui/number-field';
import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { RateSourceField } from '@/features/currency/components/RateSourceField';
import { CashSaleToggle } from '@/features/products/components/CashSaleToggle';
import { FinancingPlanPreview } from '@/features/products/components/FinancingPlanPreview';
import { FrequencyField } from '@/features/products/components/FrequencyField';
import { type ProductFieldsProps } from '@/features/products/hooks/useProductForm';
import { typedAmount } from '@/lib/amount-typing';
import { amountInput } from '@/lib/money';
import { type RateSourceChoices } from '@/types/exchange';
import { type FinancingPlanView } from '@/types/product';

interface ProductPricingFieldsProps extends ProductFieldsProps {
    plan: FinancingPlanView | null;
    rateSources: RateSourceChoices;
}

export function ProductPricingFields({ data, setData, errors, plan, rateSources }: ProductPricingFieldsProps) {
    const cash = data.cash_sale;

    const changePrice = (price: string) => setData((previous) => ({ ...previous, price, ...(previous.cash_sale ? { down_payment: price } : {}) }));

    const toggleCash = (checked: boolean) =>
        setData((previous) => ({ ...previous, cash_sale: checked, down_payment: checked ? previous.price : amountInput('0.00') }));

    return (
        <SectionCard title="Precio y plan de pago" subtitle="Así lo verá tu cliente en el catálogo.">
            <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
                <TextField
                    label="Precio"
                    name="price"
                    prefix="$"
                    inputMode="decimal"
                    value={data.price}
                    onChange={(event) => changePrice(typedAmount(event))}
                    error={errors.price}
                    autoComplete="off"
                    required
                />

                <TextField
                    label="Precio tachado (opcional)"
                    name="compare_at_price"
                    prefix="$"
                    inputMode="decimal"
                    value={data.compare_at_price}
                    onChange={(event) => setData('compare_at_price', typedAmount(event))}
                    error={errors.compare_at_price}
                    hint="Tiene que ser mayor que el precio de venta, o la oferta sería falsa."
                    autoComplete="off"
                />
            </div>

            <CashSaleToggle checked={cash} suggest={!cash && plan?.isCashSale === true} onChange={toggleCash} />

            <div className={cash ? 'hidden' : 'grid grid-cols-1 gap-x-3 sm:grid-cols-3'}>
                <TextField
                    label="Cuota inicial"
                    name="down_payment"
                    prefix="$"
                    inputMode="decimal"
                    value={data.down_payment}
                    onChange={(event) => setData('down_payment', typedAmount(event))}
                    error={errors.down_payment}
                    hint={
                        plan !== null && Number(plan.downPayment.cents) === 0
                            ? 'Sin inicial: el apartado arranca activo y solo se paga en cuotas.'
                            : 'Menor que el precio: lo que queda es lo que se financia.'
                    }
                    autoComplete="off"
                    required
                />

                <NumberField
                    label="Número de cuotas"
                    name="installments_count"
                    min={1}
                    max={36}
                    value={data.installments_count}
                    onChange={(count) => setData('installments_count', count)}
                    error={errors.installments_count}
                    required
                />

                <FrequencyField value={data.frequency_days} onChange={(days) => setData('frequency_days', days)} error={errors.frequency_days} />
            </div>

            <FinancingPlanPreview plan={plan} />

            <RateSourceField
                choices={rateSources}
                value={data.rate_source}
                onChange={(rateSource) => setData('rate_source', rateSource)}
                error={errors.rate_source}
                hint="Solo cambia cuántos bolívares ve tu cliente; el precio en dólares es el mismo."
            />
        </SectionCard>
    );
}
