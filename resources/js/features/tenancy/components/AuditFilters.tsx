import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { AUDIT_PERIODS, type AuditPeriod, periodOf, periodRange } from '@/features/tenancy/lib/audit-period';
import { type AuditActorOption, type AuditEventGroup, type AuditFilters as Filters } from '@/types/audit';
import { router } from '@inertiajs/react';
import { type FormEventHandler, useState } from 'react';

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
    const [area, setArea] = useState(() => events.find((group) => group.events.some((option) => option.value === filters.event))?.area ?? ANY);

    const pickArea = (picked: string) => {
        setArea(picked);

        const group = events.find((candidate) => candidate.area === picked);

        if (group !== undefined && !group.events.some((option) => option.value === event)) {
            setEvent(ANY);
        }
    };

    const pickPeriod = (picked: string) => {
        if (picked === ANY) {
            return;
        }

        const range = periodRange(picked as AuditPeriod);
        setFrom(range.from);
        setTo(range.to);
    };

    const submit: FormEventHandler = (submitted) => {
        submitted.preventDefault();

        const query = Object.fromEntries(Object.entries({ event, actor, from, to }).filter(([, value]) => value !== ANY));

        router.get('/backoffice/auditoria', query, { preserveState: false, preserveScroll: true });
    };

    const eventOptions = [
        { value: ANY, label: 'Evento: cualquiera' },
        ...events
            .filter((group) => area === ANY || group.area === area)
            .flatMap((group) => group.events.map((option) => ({ value: option.value, label: `${group.label} · ${option.label}` }))),
    ];

    const actorOptions = [
        { value: ANY, label: 'Persona: cualquiera' },
        ...actors.map((candidate) => ({
            value: String(candidate.id),
            label: candidate.isActive ? candidate.name : `${candidate.name} (sin acceso)`,
        })),
    ];

    return (
        <form className="flex flex-wrap items-center gap-2" onSubmit={submit}>
            <SelectField
                label="Área"
                hideLabel
                inline
                name="area"
                options={[{ value: ANY, label: 'Área: todas' }, ...events.map((group) => ({ value: group.area, label: group.label }))]}
                value={area}
                onChange={(changed) => pickArea(changed.target.value)}
            />

            <SelectField
                label="Tipo"
                hideLabel
                inline
                name="event"
                options={eventOptions}
                value={event}
                onChange={(changed) => setEvent(changed.target.value)}
            />

            <SelectField
                label="Quién"
                hideLabel
                inline
                name="actor"
                options={actorOptions}
                value={actor}
                onChange={(changed) => setActor(changed.target.value)}
            />

            <SelectField
                label="Periodo"
                hideLabel
                inline
                options={[
                    { value: ANY, label: 'Periodo: personalizado' },
                    ...AUDIT_PERIODS.map((period) => ({ value: period.value, label: period.label })),
                ]}
                value={periodOf(from, to) ?? ANY}
                onChange={(changed) => pickPeriod(changed.target.value)}
            />

            <label className="input w-auto">
                <span className="text-ink-3 text-[13px]">Desde</span>
                <input name="from" type="date" aria-label="Desde" value={from} onChange={(changed) => setFrom(changed.target.value)} />
                <span className="text-ink-3 text-[13px]">hasta</span>
                <input name="to" type="date" aria-label="Hasta" value={to} onChange={(changed) => setTo(changed.target.value)} />
            </label>

            <Button type="submit" variant="soft">
                Filtrar
            </Button>

            <Button type="button" variant="ghost" onClick={() => router.get('/backoffice/auditoria', {}, { preserveState: false })}>
                Limpiar
            </Button>
        </form>
    );
}
