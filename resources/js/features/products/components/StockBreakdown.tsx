import { Alert } from '@/components/ui/alert';
import { type StockBreakdownView } from '@/types/product';

/**
 * El reparto de las existencias de un producto, bajo su campo (`REQ-LAY-003`
 * RB-6, SP-2; `REQ-BOF-020` SP-6).
 *
 * «Existencias» es lo que hay en la tienda, apartadas incluidas. Aquí se dice
 * cuántas de esas están comprometidas y cuántas quedan para apartar, y —si el
 * negocio declaró menos de las que tiene apartadas— **cuántas faltan**. Eso
 * se permite: puede haber pérdida física, y cancelar apartados de clientes que
 * ya pagaron sería peor. Pero no se calla.
 *
 * Las tres cifras llegan del servidor y son las **guardadas**: la pantalla no
 * resta nada. El aviso aparece al volver del guardado, que es cuando es cierto.
 */
interface StockBreakdownProps {
    breakdown: StockBreakdownView;
    /** Las existencias guardadas, tal como llegaron del servidor. */
    onHand: number;
}

export function StockBreakdown({ breakdown, onHand }: StockBreakdownProps) {
    if (breakdown.reserved === 0 && breakdown.shortfall === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2">
            <p className="text-sm">
                <span className="font-medium">{units(breakdown.reserved)} en apartados vigentes</span>
                {' · '}
                {breakdown.available === 0 ? 'ninguna disponible para apartar' : `${units(breakdown.available)} disponibles para apartar`}
            </p>

            {breakdown.shortfall > 0 ? (
                <Alert tone="warning">
                    Faltan {units(breakdown.shortfall)} para cubrir los apartados vigentes: tienes {units(breakdown.reserved)} apartadas y declaraste{' '}
                    {units(onHand)} en existencias. Los apartados siguen en pie y no se cancela ninguno; si recuperas o repones unidades, actualiza
                    las existencias.
                </Alert>
            ) : null}
        </div>
    );
}

function units(count: number): string {
    return count === 1 ? '1 unidad' : `${count} unidades`;
}
