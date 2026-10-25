import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { type ChipTone, IconChip } from '@/components/ui/icon-chip';
import { type AuditActorKind, type AuditEntry } from '@/types/audit';
import { cilGlobeAlt, cilSettings, cilUser } from '@coreui/icons';

const ACTOR_TONES: Record<AuditActorKind, 'neutral' | 'info' | 'warning'> = {
    team: 'neutral',
    platform: 'warning',
    system: 'info',
};

const ACTOR_LABELS: Record<AuditActorKind, string> = {
    team: 'Equipo',
    platform: 'Apartar',
    system: 'Sistema',
};

const ACTOR_ICONS: Record<AuditActorKind, string[]> = {
    team: cilUser,
    platform: cilGlobeAlt,
    system: cilSettings,
};

const ACTOR_CHIPS: Record<AuditActorKind, ChipTone> = {
    team: 'primary',
    platform: 'warning',
    system: 'neutral',
};

interface AuditEntryRowProps {
    entry: AuditEntry;
    area?: string;
    timezone: string;
}

function readable(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'sí' : 'no';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

export function AuditEntryRow({ entry, timezone, area }: AuditEntryRowProps) {
    const all = Object.entries(entry.properties.changed ?? {});
    const reason = all.find(([field]) => field === 'reason')?.[1].after;
    const changed = all.filter(([field]) => field !== 'reason');
    const motive = typeof reason === 'string' && reason !== '' ? reason : null;

    const time = entry.occurredAt
        ? new Intl.DateTimeFormat('es-VE', { timeStyle: 'short', timeZone: timezone }).format(new Date(entry.occurredAt))
        : '—';

    return (
        <li className="flex items-start gap-3 py-3.5 first:pt-0 last:pb-0">
            <IconChip icon={ACTOR_ICONS[entry.actor.kind]} tone={ACTOR_CHIPS[entry.actor.kind]} />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-bold">{entry.eventLabel}</span>
                    {area ? <span className="badge badge-neutral badge-count">{area}</span> : null}
                </p>
                <p className="text-ink-2 text-[13px]">{entry.description}</p>
                <p className="text-ink-3 flex flex-wrap items-center gap-1.5 text-xs">
                    <Avatar name={entry.actor.name} size={18} tone="neutral" />
                    <span>{entry.actor.name}</span>
                    <Badge tone={ACTOR_TONES[entry.actor.kind]}>{ACTOR_LABELS[entry.actor.kind]}</Badge>
                    <span aria-hidden="true">·</span>
                    <time dateTime={entry.occurredAt ?? undefined}>{time}</time>
                </p>

                {changed.length === 0 && motive === null ? (
                    <span className="text-ink-3 text-xs">Sin detalle</span>
                ) : (
                    <details className="group mt-1">
                        <summary className="text-ink-2 hover:text-base-content cursor-pointer text-[13px] font-semibold">Ver cambios</summary>
                        <dl className="bg-surface-2 mt-2 flex flex-col gap-2 rounded-xl p-3 text-xs">
                            {changed.map(([field, change]) => (
                                <div key={field} className="grid gap-2 sm:grid-cols-[minmax(0,10rem)_1fr_1fr]">
                                    <dt className="text-ink-2 font-semibold">{field}</dt>
                                    <dd className="bg-error-soft rounded-lg px-2.5 py-1.5">
                                        <span className="sr-only">Antes: </span>
                                        <span className="line-through">{readable(change.before)}</span>
                                    </dd>
                                    <dd className="bg-success-soft rounded-lg px-2.5 py-1.5">
                                        <span className="sr-only">Después: </span>
                                        {readable(change.after)}
                                    </dd>
                                </div>
                            ))}

                            {motive !== null ? (
                                <div className="border-line flex flex-col gap-0.5 border-t pt-2 first:border-t-0 first:pt-0">
                                    <dt className="text-ink-2 font-semibold">Motivo</dt>
                                    <dd className="text-base-content">{motive}</dd>
                                </div>
                            ) : null}

                            {entry.properties.truncated ? <p className="text-ink-3">Detalle resumido por tamaño.</p> : null}
                        </dl>
                    </details>
                )}
            </div>
        </li>
    );
}
