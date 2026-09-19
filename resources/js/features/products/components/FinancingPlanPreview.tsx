import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type FinancingPlanView } from '@/types/product';

/**
 * La previsualización del plan de pago (`REQ-BOF-020` RB-7).
 *
 * «Inicial $50 + 5 cuotas de $30 cada 15 días», con las cifras **que el
 * servidor ya repartió**. Este archivo no divide, no suma y no formatea: pinta
 * lo que recibe. Es la mitad visible de la decisión que explica
 * `useProductForm`, y la razón por la que aquí no hay ninguna operación
 * aritmética que alguien pueda «mejorar» mañana.
 *
 * ## La última cuota se dice, no se promedia
 *
 * `Money::allocateEvenly()` deja el residuo en la última: repartir $100 en tres
 * da `[33.33, 33.33, 33.34]`. Enseñar «3 × $33.33» sería enseñar una cuota que
 * no se cobra, y enseñar una media sería enseñar una que no existe. Cuando la
 * última difiere se dice con esas palabras, que es lo que `REQ-CAT-003` RB-2
 * pedirá también en la ficha pública.
 *
 * Sin plan —cero cuotas, o la inicial por encima del precio— se pinta el hueco
 * con lo que falta. Es el estado normal de un formulario a medio escribir y no
 * merece un error: el `422` ya llega al guardar, junto a su campo.
 */

interface FinancingPlanPreviewProps {
    plan: FinancingPlanView | null;
}

export function FinancingPlanPreview({ plan }: FinancingPlanPreviewProps) {
    if (plan === null) {
        return (
            <div className="bg-base-200 rounded-box p-4 text-sm opacity-70">
                Completa el precio, la cuota inicial y el número de cuotas para ver el plan de pago. La inicial tiene que ser menor que el precio.
            </div>
        );
    }

    return (
        <div className="bg-base-200 rounded-box flex flex-col gap-2 p-4">
            <p className="text-sm">
                Inicial <MoneyLabel money={plan.downPayment} className="font-semibold" /> + {plan.installmentsCount}{' '}
                {plan.installmentsCount === 1 ? 'cuota' : 'cuotas'} de <MoneyLabel money={plan.installmentAmount} className="font-semibold" /> cada{' '}
                {plan.frequencyDays} {plan.frequencyDays === 1 ? 'día' : 'días'}.
            </p>

            {plan.hasUnevenLastInstallment ? (
                <p className="text-xs opacity-70">
                    Última cuota: <MoneyLabel money={plan.lastInstallmentAmount} />. El reparto no es exacto y el resto va a la última, para que la
                    suma cuadre al céntimo.
                </p>
            ) : null}

            <p className="text-xs opacity-70">
                Se financian <MoneyLabel money={plan.financedAmount} /> de un total de <MoneyLabel money={plan.total} />.
            </p>
        </div>
    );
}
