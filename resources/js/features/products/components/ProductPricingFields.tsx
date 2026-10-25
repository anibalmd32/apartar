import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { FinancingPlanPreview } from '@/features/products/components/FinancingPlanPreview';
import { FrequencyField } from '@/features/products/components/FrequencyField';
import { type ProductFieldsProps } from '@/features/products/hooks/useProductForm';
import { type FinancingPlanView } from '@/types/product';

interface ProductPricingFieldsProps extends ProductFieldsProps {
    plan: FinancingPlanView | null;
}

export function ProductPricingFields({ data, setData, errors, plan }: ProductPricingFieldsProps) {
    return (
        <SectionCard title="Precio y plan de pago" subtitle="Así lo verá tu cliente en el catálogo.">
            <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
                <TextField
                    label="Precio"
                    name="price"
                    prefix="$"
                    inputMode="decimal"
                    value={data.price}
                    onChange={(event) => setData('price', event.target.value)}
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
                    onChange={(event) => setData('compare_at_price', event.target.value)}
                    error={errors.compare_at_price}
                    hint="Tiene que ser mayor que el precio de venta, o la oferta sería falsa."
                    autoComplete="off"
                />
            </div>

            <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-3">
                <TextField
                    label="Cuota inicial"
                    name="down_payment"
                    prefix="$"
                    inputMode="decimal"
                    value={data.down_payment}
                    onChange={(event) => setData('down_payment', event.target.value)}
                    error={errors.down_payment}
                    hint="Menor que el precio: lo que queda es lo que se financia."
                    autoComplete="off"
                    required
                />

                <TextField
                    label="Número de cuotas"
                    name="installments_count"
                    type="number"
                    min={1}
                    max={36}
                    value={data.installments_count}
                    onChange={(event) => setData('installments_count', Number(event.target.value))}
                    error={errors.installments_count}
                    autoComplete="off"
                    required
                />

                <FrequencyField value={data.frequency_days} onChange={(days) => setData('frequency_days', days)} error={errors.frequency_days} />
            </div>

            <FinancingPlanPreview plan={plan} />
        </SectionCard>
    );
}
