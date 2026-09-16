import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type AuditActorOption, type AuditEventGroup, type AuditFilters as Filters } from '@/types/audit';
import { router } from '@inertiajs/react';
import { type FormEventHandler, useState } from 'react';

/**
 * Los tres filtros de `REQ-BOF-005` RB-9: actor, tipo y rango de fechas.
 *
 * **Van por `GET` y no por estado local**, y eso es lo que hace que el
 * resultado sea un enlace que se puede pegar en un correo: la pregunta que se
 * responde aquí —«¿qué hizo Ana el día 12?»— casi siempre hay que enseñársela a
 * alguien. Con el filtro en memoria, compartirlo sería contar los pasos.
 *
 * Los tipos llegan **agrupados por los seis bloques de RB-1** y se aplanan con
 * el bloque delante —«Cobranza · Comprobante aprobado»—. Un `optgroup` se leería
 * algo mejor y costaría escribir un `<select>` en crudo en la página, que es lo
 * que `REQ-QAX-002` RB-5 prohíbe: los átomos se envuelven una vez en
 * `components/ui` o el sistema visual deja de ser cambiable en un solo sitio.
 * La agrupación la manda el servidor desde `AuditArea`; escribirla aquí sería
 * el segundo censo de los mismos treinta y cinco casos.
 */

interface AuditFiltersProps {
    filters: Filters;
    events: readonly AuditEventGroup[];
    actors: readonly AuditActorOption[];
}

const ANY = '';

export function AuditFilters({ filters, events, actors }: AuditFiltersProps) {
    const [event, setEvent] = useState(filters.event ?? ANY);
    const [actor, setActor] = useState(filters.actor === null ? ANY : String(filters.actor));
    const [from, setFrom] = useState(filters.from ?? ANY);
    const [to, setTo] = useState(filters.to ?? ANY);

    const submit: FormEventHandler = (submitted) => {
        submitted.preventDefault();

        // Solo viaja lo que se pidió: un `?event=&actor=` vacío ensucia el
        // enlace que se va a compartir y no filtra nada.
        const query = Object.fromEntries(Object.entries({ event, actor, from, to }).filter(([, value]) => value !== ANY));

        router.get('/backoffice/auditoria', query, { preserveState: false, preserveScroll: true });
    };

    const eventOptions = [
        { value: ANY, label: 'Cualquier tipo' },
        ...events.flatMap((group) => group.events.map((option) => ({ value: option.value, label: `${group.label} · ${option.label}` }))),
    ];

    const actorOptions = [
        { value: ANY, label: 'Cualquier persona' },
        ...actors.map((candidate) => ({
            value: String(candidate.id),
            // Los desactivados se marcan y **siguen en la lista**: la pregunta
            // que trae aquí suele ser sobre quien ya no está.
            label: candidate.isActive ? candidate.name : `${candidate.name} (sin acceso)`,
        })),
    ];

    return (
        <form className="grid gap-2 sm:grid-cols-2 lg:grid-cols-5" onSubmit={submit}>
            <SelectField label="Tipo" name="event" options={eventOptions} value={event} onChange={(changed) => setEvent(changed.target.value)} />

            <SelectField label="Quién" name="actor" options={actorOptions} value={actor} onChange={(changed) => setActor(changed.target.value)} />

            <TextField label="Desde" name="from" type="date" value={from} onChange={(changed) => setFrom(changed.target.value)} />

            <TextField label="Hasta" name="to" type="date" value={to} onChange={(changed) => setTo(changed.target.value)} />

            <div className="flex items-end gap-2">
                <Button type="submit">Filtrar</Button>

                <Button type="button" variant="ghost" onClick={() => router.get('/backoffice/auditoria', {}, { preserveState: false })}>
                    Limpiar
                </Button>
            </div>
        </form>
    );
}
