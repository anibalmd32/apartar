import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type ProductDetail } from '@/types/catalog-view';

/**
 * Cuánto se paga y cuándo, en la ficha (`REQ-CAT-003` RB-2, RB-3).
 *
 * Todas las cifras llegan **repartidas y proyectadas** del servidor: la cuota
 * es la de `FinancingPlan`, la misma que enseña la card de la parrilla, y aquí
 * no se divide nada. `MoneyLabel` elige qué moneda va delante; sin tasa, solo
 * dólares (`REQ-FX-005` RB-4).
 *
 * RB-2: cuando el reparto deja el residuo en la última cuota, se dice con esas
 * palabras —«Última cuota»— en vez de enseñar una cuota que no se cobra.
 * La frecuencia, en lenguaje natural (RB-3): «cada 15 días».
 */

function frequencyLabel(days: number): string {
    return days === 1 ? 'cada día' : `cada ${days} días`;
}

interface PlanBreakdownProps {
    product: ProductDetail;
}

export function PlanBreakdown({ product }: PlanBreakdownProps) {
    return (
        <dl className="bg-base-100 rounded-box grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 p-4 text-sm">
            <dt className="opacity-70">Precio</dt>
            <dd>
                <MoneyLabel money={product.price} className="text-lg font-semibold" />
                {product.compareAtPrice !== null ? (
                    <span className="ml-2 line-through opacity-60">
                        <MoneyLabel money={product.compareAtPrice} />
                    </span>
                ) : null}
            </dd>

            {product.installmentAmount !== null ? (
                <>
                    <dt className="opacity-70">Inicial</dt>
                    <dd>
                        <MoneyLabel money={product.downPayment} className="font-medium" />
                    </dd>

                    <dt className="opacity-70">Cuotas</dt>
                    <dd>
                        {product.installmentsCount} {product.installmentsCount === 1 ? 'cuota' : 'cuotas'} de{' '}
                        <MoneyLabel money={product.installmentAmount} className="font-medium" /> {frequencyLabel(product.frequencyDays)}
                    </dd>

                    {product.lastInstallmentAmount !== null ? (
                        <>
                            <dt className="opacity-70">Última cuota</dt>
                            <dd>
                                <MoneyLabel money={product.lastInstallmentAmount} className="font-medium" />
                            </dd>
                        </>
                    ) : null}
                </>
            ) : null}
        </dl>
    );
}
