import { Meter } from '@/components/ui/meter';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type SummarySafeguard as SummarySafeguardView } from '@/types/catalog-summary';

function note(safeguard: SummarySafeguardView): string {
    const missing = safeguard.payments - safeguard.recorded;
    const recorded = `${safeguard.recorded} de ${safeguard.payments} pagos tienen registrado dónde quedó el dinero${missing > 0 ? '; los demás aparecen abajo como «Sin registrar».' : '.'}`;
    const old =
        safeguard.withoutRate === 0
            ? ''
            : ` ${safeguard.withoutRate} ${safeguard.withoutRate === 1 ? 'pago es anterior' : 'pagos son anteriores'} al histórico del paralelo y no ${safeguard.withoutRate === 1 ? 'suma' : 'suman'}.`;

    return recorded + old;
}

export function SummarySafeguard({ safeguard }: { safeguard: SummarySafeguardView }) {
    const largest = Math.max(1, ...safeguard.byDestination.map((row) => Number(row.amount.cents)));

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="flex flex-col">
                    <span className="text-ink-3 text-[13px]">Vale en dólar paralelo</span>
                    <MoneyLabel money={safeguard.parallel} className="text-2xl font-bold" />
                </div>
                <div className="flex flex-col items-end">
                    <span className="text-ink-3 text-[13px]">Diferencia con lo cobrado</span>
                    <MoneyLabel
                        money={safeguard.gap}
                        className={Number(safeguard.gap.cents) < 0 ? 'text-error text-xl font-bold' : 'text-xl font-bold'}
                    />
                </div>
            </div>
            <p className="text-ink-2 text-xs">{note(safeguard)}</p>
            {safeguard.byDestination.length > 0 ? (
                <ul className="flex flex-col gap-3" aria-label="Dónde está resguardado">
                    {safeguard.byDestination.map((row) => (
                        <Meter
                            key={row.label}
                            as="li"
                            label={`${row.label} · ${row.payments} ${row.payments === 1 ? 'pago' : 'pagos'}`}
                            value={<MoneyLabel money={row.amount} compact />}
                            percentage={(Number(row.amount.cents) * 100) / largest}
                            warn={!row.recorded}
                        />
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
