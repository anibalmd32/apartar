import { type ChipTone, IconChip } from '@/components/ui/icon-chip';
import { SectionCard } from '@/components/ui/section-card';
import { type LayawayActivityEvent } from '@/types/layaway-backoffice';
import { cilBan, cilBasket, cilCheckCircle, cilDescription, cilMoney, cilTruck, cilWarning, cilXCircle } from '@coreui/icons';

const LOOK: Record<string, { icon: string[]; tone: ChipTone }> = {
    created: { icon: cilBasket, tone: 'primary' },
    activated: { icon: cilCheckCircle, tone: 'success' },
    receipt_submitted: { icon: cilDescription, tone: 'warning' },
    receipt_approved: { icon: cilCheckCircle, tone: 'success' },
    receipt_rejected: { icon: cilXCircle, tone: 'error' },
    payment_recorded: { icon: cilMoney, tone: 'success' },
    installment_overdue: { icon: cilWarning, tone: 'error' },
    delivered: { icon: cilTruck, tone: 'info' },
    cancelled: { icon: cilBan, tone: 'neutral' },
};

const FALLBACK = { icon: cilDescription, tone: 'neutral' as ChipTone };

export function LayawayActivity({ events, timezone }: { events: readonly LayawayActivityEvent[]; timezone?: string }) {
    if (events.length === 0) {
        return null;
    }

    const format = new Intl.DateTimeFormat('es-VE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: timezone });

    return (
        <SectionCard title="Actividad">
            <ol className="flex flex-col gap-3.5">
                {events.map((event, index) => {
                    const look = LOOK[event.kind] ?? FALLBACK;

                    return (
                        <li key={`${event.kind}-${index}`} className="flex items-start gap-3">
                            <IconChip icon={look.icon} tone={look.tone} />
                            <span className="flex min-w-0 flex-col gap-0.5">
                                <span className="text-[13px] font-semibold">{event.title}</span>
                                {event.detail ? <span className="text-ink-2 text-xs">{event.detail}</span> : null}
                                <span className="text-ink-3 text-xs">
                                    {event.at ? format.format(new Date(event.at)) : ''}
                                    {event.actor ? ` · ${event.actor}` : ''}
                                </span>
                            </span>
                        </li>
                    );
                })}
            </ol>
        </SectionCard>
    );
}
