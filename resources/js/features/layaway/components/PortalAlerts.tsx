import { Alert, type AlertTone } from '@/components/ui/alert';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { cn } from '@/lib/utils';
import { type PortalAlert } from '@/types/portal';
import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

const VISIBLE = 3;

interface PortalAlertsProps {
    alerts: readonly PortalAlert[];
}

export function PortalAlerts({ alerts }: PortalAlertsProps) {
    const [expanded, setExpanded] = useState(false);

    if (alerts.length === 0) {
        return null;
    }

    const shown = expanded ? alerts : alerts.slice(0, VISIBLE);

    return (
        <section aria-label="Avisos" className="flex flex-col gap-2">
            <ul className="flex flex-col gap-2">
                {shown.map((alert, index) => (
                    <li key={`${alert.kind}-${index}`}>
                        <AlertItem alert={alert} />
                    </li>
                ))}
            </ul>
            {!expanded && alerts.length > VISIBLE ? (
                <button type="button" className="btn btn-ghost btn-sm self-start" onClick={() => setExpanded(true)}>
                    Ver todos los avisos ({alerts.length})
                </button>
            ) : null}
        </section>
    );
}

function AlertItem({ alert }: { alert: PortalAlert }) {
    const { tone, message, action } = describe(alert);

    return (
        <Alert tone={tone}>
            <span className="flex flex-col items-start gap-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <span>{message}</span>
                <Link href={alert.actionUrl} className={cn('btn btn-sm shrink-0', tone === 'error' && 'btn-primary')}>
                    {action}
                </Link>
            </span>
        </Alert>
    );
}

function plural(count: number, one: string, many: string): string {
    return count === 1 ? one : many;
}

function timeLeft(hours: number, minutes: number): string {
    if (hours >= 1) {
        return `${hours} ${plural(hours, 'hora', 'horas')}`;
    }

    return `${minutes} ${plural(minutes, 'minuto', 'minutos')}`;
}

function describe(alert: PortalAlert): { tone: AlertTone; message: ReactNode; action: string } {
    switch (alert.kind) {
        case 'down_payment_window':
            return {
                tone: 'warning',
                message: (
                    <>
                        <strong>{alert.businessName}:</strong> te quedan {timeLeft(alert.hoursLeft, alert.minutesLeft)} para pagar la inicial de{' '}
                        <span className="font-mono">{alert.layawayCode}</span>.
                    </>
                ),
                action: 'Pagar la inicial',
            };
        case 'overdue':
            return {
                tone: 'error',
                message: (
                    <>
                        {alert.businessName !== null ? <strong>{alert.businessName}: </strong> : 'Entre tus negocios tienes '}
                        {alert.count} {plural(alert.count, 'cuota vencida', 'cuotas vencidas')} por <MoneyLabel money={alert.amount} />.
                    </>
                ),
                action: 'Ver cómo pagar',
            };
        case 'receipt_rejected':
            return {
                tone: 'error',
                message: (
                    <>
                        <strong>{alert.businessName}:</strong> rechazó tu comprobante de <span className="font-mono">{alert.layawayCode}</span>.
                        Revisa el motivo y envía otro.
                    </>
                ),
                action: 'Ver el motivo',
            };
        case 'due_soon':
            return {
                tone: 'warning',
                message: (
                    <>
                        <strong>{alert.businessName}:</strong> <MoneyLabel money={alert.amount} /> vence el {alert.dueDateLabel}.
                    </>
                ),
                action: 'Ver mis cuotas',
            };
        case 'receipt_pending':
            return {
                tone: 'info',
                message: `${alert.count} ${plural(alert.count, 'comprobante en revisión', 'comprobantes en revisión')}. El negocio lo está revisando.`,
                action: 'Ver apartado',
            };
        case 'claim_available':
            return {
                tone: 'info',
                message: `¿Tienes apartados en ${alert.businessName}? Puedes reunirlos aquí.`,
                action: 'Reclamar mi historial',
            };
    }
}
