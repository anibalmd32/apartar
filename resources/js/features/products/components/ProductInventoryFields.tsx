import { NumberField } from '@/components/ui/number-field';
import { SectionCard } from '@/components/ui/section-card';
import { StockBreakdown } from '@/features/products/components/StockBreakdown';
import { type ProductFieldsProps } from '@/features/products/hooks/useProductForm';
import { type ProductFormValues } from '@/types/product';

interface ProductInventoryFieldsProps extends ProductFieldsProps {
    saved: ProductFormValues | null;
}

export function ProductInventoryFields({ data, setData, errors, saved }: ProductInventoryFieldsProps) {
    return (
        <SectionCard title="Inventario">
            <NumberField
                label="Existencias"
                name="stock"
                min={0}
                value={data.stock}
                onChange={(stock) => setData('stock', stock)}
                error={errors.stock}
                hint="Las que tienes físicamente en la tienda, incluidas las apartadas que aún no entregas. Cero no despublica el producto: se muestra agotado y no se puede apartar."
                required
            />

            {saved === null ? null : <StockBreakdown breakdown={saved.stockBreakdown} onHand={saved.stock} />}
        </SectionCard>
    );
}
