import { type ChipTone, IconChip } from '@/components/ui/icon-chip';
import { SectionCard } from '@/components/ui/section-card';
import { formatDateTime } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { type LayawayActivityEvent } from '@/types/layaway-backoffice';
import {
    cilArrowLeft,
    cilArrowRight,
    cilBan,
    cilBasket,
    cilCalendar,
    cilCheckCircle,
    cilDescription,
    cilMoney,
    cilPencil,
    cilShieldAlt,
    cilSwapHorizontal,
    cilTrash,
    cilTruck,
    cilWarning,
    cilXCircle,
} from '@coreui/icons';
import { Link } from '@inertiajs/react';

const LOOK: Record<string, { icon: string[]; tone: ChipTone }> = {
    created: { icon: cilBasket, tone: 'primary' },
    activated: { icon: cilCheckCircle, tone: 'success' },
    receipt_submitted: { icon: cilDescription, tone: 'warning' },
    receipt_approved: { icon: cilCheckCircle, tone: 'success' },
    receipt_rejected: { icon: cilXCircle, tone: 'error' },
    payment_recorded: { icon: cilMoney, tone: 'success' },
    payment_edited: { icon: cilPencil, tone: 'info' },
    payment_voided: { icon: cilBan, tone: 'error' },
    payment_transferred_out: { icon: cilArrowRight, tone: 'warning' },
    payment_transferred_in: { icon: cilArrowLeft, tone: 'success' },
    payment_reallocated: { icon: cilSwapHorizontal, tone: 'info' },
    installment_rescheduled: { icon: cilCalendar, tone: 'info' },
    installment_overdue: { icon: cilWarning, tone: 'error' },
    safeguard_recorded: { icon: cilShieldAlt, tone: 'success' },
    safeguard_edited: { icon: cilShieldAlt, tone: 'info' },
    safeguard_removed: { icon: cilTrash, tone: 'neutral' },
    delivered: { icon: cilTruck, tone: 'info' },
    cancelled: { icon: cilBan, tone: 'neutral' },
};

const FALLBACK = { icon: cilDescription, tone: 'neutral' as ChipTone };

export function LayawayActivity({ events, timezone }: { events: readonly LayawayActivityEvent[]; timezone?: string }) {
    if (events.length === 0) {
        return null;
    }

    return (
        <SectionCard title="Actividad">
            <ul className="timeline timeline-vertical timeline-compact timeline-snap-icon" aria-label="Actividad del apartado">
                {events.map((event, index) => {
                    const look = LOOK[event.kind] ?? FALLBACK;

                    return (
                        <li key={`${event.kind}-${event.at}-${index}`} data-kind={event.kind}>
                            {index > 0 ? <hr className="bg-line" /> : null}
                            <div className="timeline-middle">
                                <IconChip icon={look.icon} tone={look.tone} />
                            </div>
                            <div className={cn('timeline-end flex min-w-0 flex-col gap-0.5 pl-1', index < events.length - 1 && 'mb-4')}>
                                <span className="text-[13px] font-semibold">{event.title}</span>
                                {event.detail ? <span className="text-ink-2 text-xs break-words">{event.detail}</span> : null}
                                {event.link ? (
                                    <Link href={event.link.url} className="link link-primary hit-area self-start text-xs">
                                        Ver el apartado {event.link.label}
                                    </Link>
                                ) : null}
                                <span className="text-ink-3 text-xs">
                                    {event.at ? formatDateTime(event.at, timezone) : ''}
                                    {event.actor ? ` · ${event.actor}` : ''}
                                </span>
                            </div>
                            {index < events.length - 1 ? <hr className="bg-line" /> : null}
                        </li>
                    );
                })}
            </ul>
        </SectionCard>
    );
}
