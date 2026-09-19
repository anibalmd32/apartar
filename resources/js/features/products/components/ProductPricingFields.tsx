import { TextField } from '@/components/ui/text-field';
import { FinancingPlanPreview } from '@/features/products/components/FinancingPlanPreview';
import { type ProductFieldsProps } from '@/features/products/hooks/useProductForm';
import { type FinancingPlanView } from '@/types/product';

/**
 * Precio, plan de pago y existencias (`REQ-BOF-020` RB-1, RB-2, RB-7).
 *
 * Es el bloque donde están las seis reglas de coherencia, y por eso cada pista
 * dice **qué** se espera en vez de esperar al `422`: «menor que el precio» al
 * lado de la inicial evita el rechazo más frecuente del formulario.
 *
 * Los `min` y `max` de los campos numéricos son una ayuda del navegador y no
 * la validación: quien manda es `CoherentFinancingTerms` en el servidor, que
 * es la única que ve los cinco campos a la vez.
 */

interface ProductPricingFieldsProps extends ProductFieldsProps {
    plan: FinancingPlanView | null;
}

export function ProductPricingFields({ data, setData, errors, plan }: ProductPricingFieldsProps) {
    return (
        <section className="card bg-base-100 shadow-sm">
            <div className="card-body grid gap-2 sm:grid-cols-2">
                <h2 className="card-title text-base sm:col-span-2">Precio y plan de pago</h2>

                {/* Los importes se escriben en dólares con dos decimales
                    (RB-2). `inputMode="decimal"` saca el teclado numérico en el
                    móvil sin convertir el campo en un `number`, que en varios
                    navegadores acepta notación exponencial y se come los ceros
                    a la derecha. */}
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
                    label="Precio tachado"
                    name="compare_at_price"
                    prefix="$"
                    inputMode="decimal"
                    value={data.compare_at_price}
                    onChange={(event) => setData('compare_at_price', event.target.value)}
                    error={errors.compare_at_price}
                    hint="Opcional. Tiene que ser mayor que el precio de venta, o la oferta sería falsa."
                    autoComplete="off"
                />

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
                    label="Existencias"
                    name="stock"
                    type="number"
                    min={0}
                    value={data.stock}
                    onChange={(event) => setData('stock', Number(event.target.value))}
                    error={errors.stock}
                    hint="Cero no despublica el producto: se muestra agotado y no se puede apartar."
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

                <TextField
                    label="Días entre cuotas"
                    name="frequency_days"
                    type="number"
                    min={1}
                    max={90}
                    value={data.frequency_days}
                    onChange={(event) => setData('frequency_days', Number(event.target.value))}
                    error={errors.frequency_days}
                    autoComplete="off"
                    required
                />

                <div className="sm:col-span-2">
                    <FinancingPlanPreview plan={plan} />
                </div>
            </div>
        </section>
    );
}
