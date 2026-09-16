import { Badge } from '@/components/ui/badge';
import { type AuditActorKind, type AuditEntry } from '@/types/audit';

/**
 * Una entrada del registro de auditoría (`REQ-BOF-005`).
 *
 * Dos decisiones de esta fila valen más que su tamaño:
 *
 *  - **el actor de la plataforma se marca**, y no es decoración: RB-6 existe
 *    para que el negocio pueda ver cuándo alguien de Apartar miró o tocó sus
 *    datos, y eso solo funciona si en la lista se distingue de un miembro del
 *    equipo a simple vista;
 *  - **el antes y el después se pintan juntos** (RB-2). La pregunta que trae
 *    aquí a alguien nunca es «¿se tocó esto?», es «¿qué decía antes?» — y un
 *    volcado de JSON no la responde.
 */

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

interface AuditEntryRowProps {
    entry: AuditEntry;
    /** Zona horaria del negocio: las fechas se leen en la suya (`REQ-ORG-006` RB-7). */
    timezone: string;
}

/**
 * Un valor de `properties` como texto.
 *
 * Los valores llegan sin tipo a propósito —caben un precio, una fecha y una
 * lista—, así que aquí se convierten con cuidado en lugar de interpolarse:
 * `String(undefined)` en la columna «antes» diría «undefined» donde lo correcto
 * es decir que el campo no existía.
 */
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

export function AuditEntryRow({ entry, timezone }: AuditEntryRowProps) {
    const changed = Object.entries(entry.properties.changed ?? {});

    const occurred = entry.occurredAt
        ? new Intl.DateTimeFormat('es-VE', { dateStyle: 'short', timeStyle: 'short', timeZone: timezone }).format(new Date(entry.occurredAt))
        : '—';

    return (
        <tr>
            <td className="align-top text-sm whitespace-nowrap">{occurred}</td>

            <td className="align-top">
                <div className="flex flex-col gap-1">
                    <span className="font-medium">{entry.eventLabel}</span>
                    <span className="text-xs opacity-70">{entry.description}</span>
                </div>
            </td>

            <td className="align-top">
                <div className="flex flex-col gap-1">
                    <span className="text-sm">{entry.actor.name}</span>
                    {/* El color nunca es lo único que distingue un estado. */}
                    <Badge tone={ACTOR_TONES[entry.actor.kind]}>{ACTOR_LABELS[entry.actor.kind]}</Badge>
                </div>
            </td>

            <td className="align-top">
                {changed.length === 0 ? (
                    <span className="text-xs opacity-60">Sin detalle</span>
                ) : (
                    <dl className="flex flex-col gap-1 text-xs">
                        {changed.map(([field, change]) => (
                            <div key={field} className="flex flex-wrap gap-1">
                                <dt className="font-medium">{field}:</dt>
                                <dd className="opacity-70">
                                    <span className="line-through">{readable(change.before)}</span> → <span>{readable(change.after)}</span>
                                </dd>
                            </div>
                        ))}

                        {entry.properties.truncated ? <p className="opacity-60">Detalle resumido por tamaño.</p> : null}
                    </dl>
                )}
            </td>
        </tr>
    );
}
