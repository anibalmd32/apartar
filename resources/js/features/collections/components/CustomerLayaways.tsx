import { Badge } from '@/components/ui/badge';
import { AmountProgress } from '@/features/collections/components/AmountProgress';
import { LayawayLink } from '@/features/collections/components/collectionCells';
import { DeliveryCell } from '@/features/collections/components/DeliveryCell';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { formatDate } from '@/lib/dates';
import { type CollectionLayawayGroup } from '@/types/collections';

function owedLabel(row: CollectionLayawayGroup): string {
    const count = `${row.installments} ${row.installments === 1 ? 'cuota' : 'cuotas'} por pagar`;

    return row.of ? `${count} de ${row.of}` : count;
}

export function CustomerLayaways({ rows }: { rows: readonly CollectionLayawayGroup[] }) {
    return (
        <ul className="divide-line flex flex-col divide-y px-3 py-1 md:pl-14" aria-label="Apartados del cliente">
            {rows.map((row) => (
                <li key={row.id} className="flex flex-wrap items-center gap-x-5 gap-y-2 py-3 text-[13px]">
                    <span className="flex min-w-0 flex-1 basis-48 flex-col">
                        <span className="flex items-center gap-2">
                            <LayawayLink layaway={row.layaway} />
                            <span className="text-ink-2 truncate">{row.productName}</span>
                        </span>
                        <span className="text-ink-3 text-xs">{owedLabel(row)}</span>
                    </span>
                    <AmountProgress paid={row.paid} total={row.total} progress={row.progress} className="basis-44" />
                    <span className="flex flex-col whitespace-nowrap">
                        <span className="text-ink-3 text-xs">Próxima</span>
                        <span className="flex items-center gap-2">
                            {formatDate(row.nextDue)}
                            {row.daysLate > 0 ? (
                                <Badge tone="error">{`${row.daysLate} ${row.daysLate === 1 ? 'día' : 'días'} de atraso`}</Badge>
                            ) : null}
                        </span>
                    </span>
                    <span className="flex flex-col items-end whitespace-nowrap">
                        <span className="text-ink-3 text-xs">Por cobrar</span>
                        <MoneyLabel money={row.owed} className="font-semibold" compact />
                    </span>
                    <DeliveryCell delivery={row.delivery} code={row.layaway.code} />
                </li>
            ))}
        </ul>
    );
}
