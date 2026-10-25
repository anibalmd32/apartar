import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { StockBreakdown } from '@/features/products/components/StockBreakdown';
import { type ProductFieldsProps } from '@/features/products/hooks/useProductForm';
import { type ProductFormValues } from '@/types/product';

interface ProductInventoryFieldsProps extends ProductFieldsProps {
    saved: ProductFormValues | null;
}

export function ProductInventoryFields({ data, setData, errors, saved }: ProductInventoryFieldsProps) {
    return (
        <SectionCard title="Inventario">
            <TextField
                label="Existencias"
                name="stock"
                type="number"
                min={0}
                value={data.stock}
                onChange={(event) => setData('stock', Number(event.target.value))}
                error={errors.stock}
                hint="Las que tienes en la tienda, apartadas incluidas. Cero no despublica el producto: se muestra agotado y no se puede apartar."
                autoComplete="off"
                required
            />

            {saved === null ? null : <StockBreakdown breakdown={saved.stockBreakdown} onHand={saved.stock} />}
        </SectionCard>
    );
}
