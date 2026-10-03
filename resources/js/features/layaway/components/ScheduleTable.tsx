import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type InstallmentRow, type InstallmentTone } from '@/types/portal';

/**
 * El cronograma del apartado (`REQ-LAY-021` RB-4, RB-5).
 *
 * Una lista y no una tabla: con 36 cuotas en 375 px, una tabla obliga a
 * desplazar el `body` en horizontal (SP-6), y cada cuota cabe entera en una
 * fila de dos líneas. El color dice lo mismo que el texto —«vencida», «vence
 * en 2 días»—, porque un estado que solo existe como color no existe para
 * quien no distingue el rojo del verde.
 *
 * Los días los cuenta el servidor en el calendario del negocio: aquí no se
 * calcula ninguna fecha.
 */

const TONES: Record<InstallmentTone, string> = {
    paid: 'badge-success',
    overdue: 'badge-error',
    soon: 'badge-warning',
    pending: 'badge-ghost',
};

function dueText(row: InstallmentRow): string {
    if (row.tone === 'paid') {
        return 'Pagada';
    }

    if (row.daysUntilDue < 0) {
        const days = -row.daysUntilDue;

        return days === 1 ? 'Venció ayer' : `Venció hace ${days} días`;
    }

    if (row.daysUntilDue === 0) {
        return 'Vence hoy';
    }

    return row.daysUntilDue === 1 ? 'Vence mañana' : `Vence en ${row.daysUntilDue} días`;
}

interface ScheduleTableProps {
    installments: readonly InstallmentRow[];
}

export function ScheduleTable({ installments }: ScheduleTableProps) {
    return (
        <ol className="flex flex-col gap-2" aria-label="Cronograma de cuotas">
            {installments.map((row) => (
                <li key={row.number} className="border-base-300 flex flex-col gap-1 rounded-lg border p-3">
                    <div className="flex items-center justify-between gap-2">
                        <span className="font-medium">
                            Cuota {row.number} · {row.dueDateLabel}
                        </span>
                        <span className={`badge shrink-0 ${TONES[row.tone]}`}>{dueText(row)}</span>
                    </div>
                    <div className="text-sm">
                        <MoneyLabel money={row.amount} />
                        {row.status === 'partially_paid' || (row.paid.cents > 0 && row.tone !== 'paid') ? (
                            <span className="opacity-70">
                                {' '}
                                · pagado <MoneyLabel money={row.paid} />, falta <MoneyLabel money={row.pending} />
                            </span>
                        ) : null}
                    </div>
                </li>
            ))}
        </ol>
    );
}
