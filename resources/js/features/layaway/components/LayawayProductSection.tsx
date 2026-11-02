import { Alert } from '@/components/ui/alert';
import { SectionCard } from '@/components/ui/section-card';
import { SelectField } from '@/components/ui/select-field';
import { QuantityStepper } from '@/features/layaway/components/QuantityStepper';
import { type LayawayProductOption } from '@/types/layaway-backoffice';

interface LayawayProductSectionProps {
    products: readonly LayawayProductOption[];
    product: LayawayProductOption | null;
    value: string;
    quantity: number;
    onProductChange: (value: string) => void;
    onQuantityChange: (quantity: number) => void;
    errors: { product?: string; quantity?: string };
}

export function quantityHint({ stock, maxQuantity }: LayawayProductOption): string {
    if (maxQuantity <= 1) {
        return stock <= 1 ? 'Se aparta una unidad: es la única en stock.' : 'Se aparta una unidad.';
    }

    return stock > maxQuantity
        ? `Hasta ${maxQuantity} unidades por apartado, aunque tienes ${stock} en stock.`
        : `Hasta ${maxQuantity} unidades: son las que quedan en stock.`;
}

export function LayawayProductSection({ products, product, value, quantity, onProductChange, onQuantityChange, errors }: LayawayProductSectionProps) {
    const max = product?.maxQuantity ?? 1;

    return (
        <SectionCard title="Producto" subtitle="Precio, inicial, cuotas y frecuencia salen del producto.">
            {products.length === 0 ? (
                <Alert tone="info">No tienes productos publicados con existencias. Publica uno o súbele el stock para apartarlo.</Alert>
            ) : (
                <SelectField
                    label="Producto"
                    name="product"
                    options={[
                        { value: '', label: 'Elige el producto' },
                        ...products.map((option) => ({
                            value: String(option.id),
                            label: `${option.name} · ${option.sku} (${option.stock} en stock)`,
                        })),
                    ]}
                    value={value}
                    onChange={(event) => onProductChange(event.target.value)}
                    error={errors.product}
                />
            )}
            {product !== null ? (
                <QuantityStepper
                    value={quantity}
                    max={max}
                    onChange={(next) => onQuantityChange(Number.isFinite(next) ? Math.min(Math.max(Math.trunc(next), 1), max) : 1)}
                    error={errors.quantity}
                    hint={quantityHint(product)}
                />
            ) : null}
        </SectionCard>
    );
}
