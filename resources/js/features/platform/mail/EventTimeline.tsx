import { SectionCard } from '@/components/ui/section-card';
import { cn } from '@/lib/utils';
import { type SentMailEventRow } from '@/types/mail';
import { dateTime } from './format';

const PROBLEMS = new Set(['bounce', 'soft_bounce', 'spam', 'reject', 'suspension', 'unsubscribe']);

export function EventTimeline({ createdAt, sentAt, events }: { createdAt: string; sentAt: string | null; events: readonly SentMailEventRow[] }) {
    return (
        <SectionCard title="Recorrido" subtitle="Lo que Mailtrap nos avisó, en orden." aria-label="Recorrido">
            <ol className="border-line ml-1.5 flex flex-col gap-4 border-l pl-4">
                <li className="relative flex flex-col gap-0.5">
                    <span className="bg-base-300 absolute top-1.5 -left-[21px] size-2.5 rounded-full" aria-hidden="true" />
                    <span className="text-sm font-semibold">{sentAt === null ? 'Preparado para enviar' : 'Entregado a Mailtrap'}</span>
                    <time className="text-ink-3 text-xs" dateTime={sentAt ?? createdAt}>
                        {dateTime(sentAt ?? createdAt)}
                    </time>
                </li>
                {events.map((event) => (
                    <li key={event.id} className="relative flex flex-col gap-0.5">
                        <span
                            className={cn(
                                'absolute top-1.5 -left-[21px] size-2.5 rounded-full',
                                PROBLEMS.has(event.type) ? 'bg-error' : 'bg-primary',
                            )}
                            aria-hidden="true"
                        />
                        <span className="text-sm font-semibold">{event.label}</span>
                        <time className="text-ink-3 text-xs" dateTime={event.occurredAt}>
                            {dateTime(event.occurredAt)}
                            {event.email !== '' ? ` · ${event.email}` : ''}
                        </time>
                        {event.url ? <span className="text-ink-2 text-xs break-all">{event.url}</span> : null}
                        {event.reason ? (
                            <span className="text-ink-2 text-xs break-words">
                                {event.responseCode ? `${event.responseCode} · ` : ''}
                                {event.bounceCategory ? `${event.bounceCategory} · ` : ''}
                                {event.reason}
                            </span>
                        ) : null}
                        {event.userAgent || event.ip ? (
                            <span className="text-ink-3 text-[11px] break-all">{[event.ip, event.userAgent].filter(Boolean).join(' · ')}</span>
                        ) : null}
                    </li>
                ))}
            </ol>
            {events.length === 0 ? <p className="text-ink-3 text-xs">Mailtrap todavía no avisó de nada sobre este correo.</p> : null}
        </SectionCard>
    );
}
