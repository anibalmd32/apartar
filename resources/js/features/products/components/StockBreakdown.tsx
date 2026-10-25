import { Alert } from '@/components/ui/alert';
import { type StockBreakdownView } from '@/types/product';

interface StockBreakdownProps {
    breakdown: StockBreakdownView;
    onHand: number;
}

export function StockBreakdown({ breakdown, onHand }: StockBreakdownProps) {
    if (breakdown.reserved === 0 && breakdown.shortfall === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2">
            <dl className="text-[13px]">
                <div className="flex justify-between gap-3 py-1">
                    <dt className="text-ink-3">Reservadas por apartados</dt>
                    <dd className="font-bold">{breakdown.reserved}</dd>
                </div>
                <div className="flex justify-between gap-3 py-1">
                    <dt className="text-ink-3">Disponibles para apartar</dt>
                    <dd className="font-bold">{breakdown.available}</dd>
                </div>
            </dl>
            <p className="sr-only">
                <span>{units(breakdown.reserved)} en apartados vigentes</span>
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
