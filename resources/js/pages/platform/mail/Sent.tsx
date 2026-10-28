import { Badge } from '@/components/ui/badge';
import { DateRangeField } from '@/components/ui/date-range-field';
import { EmptyState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { SelectField } from '@/components/ui/select-field';
import { Pager } from '@/features/platform/components/Pager';
import { count, dateTime, statusTone } from '@/features/platform/mail/format';
import { MailTabs } from '@/features/platform/mail/MailTabs';
import PlatformLayout from '@/layouts/platform-layout';
import { type SentMailRow } from '@/types/mail';
import { cilEnvelopeClosed, cilSearch } from '@coreui/icons';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface SentFilters {
    estado: string | null;
    categoria: string | null;
    q: string | null;
    desde: string | null;
    hasta: string | null;
}

interface SentProps {
    mails: readonly SentMailRow[];
    hasMore: boolean;
    page: number;
    filters: SentFilters;
    statuses: readonly { value: string; label: string }[];
    categories: readonly { value: string; label: string }[];
}

export default function SentMails({ mails, hasMore, page, filters, statuses, categories }: SentProps) {
    const [search, setSearch] = useState(filters.q ?? '');
    const visit = (changes: Partial<Record<keyof SentFilters | 'pagina', string | number | null>>) =>
        router.get('/plataforma/correos/enviados', { ...filters, pagina: 1, ...changes }, { preserveState: true, replace: true });
    const filtered = Object.values(filters).some((value) => value !== null);

    return (
        <PlatformLayout title="Correos enviados" description="Cada correo que salió de la app, con su contenido y lo que Mailtrap nos contó de él.">
            <MailTabs />

            <section aria-label="Filtros" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_180px_220px_auto]">
                <form
                    role="search"
                    onSubmit={(event) => {
                        event.preventDefault();
                        visit({ q: search.trim() === '' ? null : search.trim() });
                    }}
                    className="fieldset"
                >
                    <label className="fieldset-legend" htmlFor="sent-search">
                        Buscar
                    </label>
                    <label className="input w-full">
                        <Icon icon={cilSearch} className="text-ink-3" />
                        <input
                            id="sent-search"
                            type="search"
                            className="grow"
                            placeholder="Destinatario o asunto"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </label>
                </form>
                <SelectField
                    label="Estado"
                    value={filters.estado ?? ''}
                    onChange={(event) => visit({ estado: event.target.value === '' ? null : event.target.value })}
                    options={[{ value: '', label: 'Todos' }, ...statuses]}
                />
                <SelectField
                    label="Tipo"
                    value={filters.categoria ?? ''}
                    onChange={(event) => visit({ categoria: event.target.value === '' ? null : event.target.value })}
                    options={[{ value: '', label: 'Todos' }, ...categories]}
                />
                <DateRangeField
                    label="Fechas"
                    fromLabel="Desde"
                    from={filters.desde ?? ''}
                    to={filters.hasta ?? ''}
                    fromName="desde"
                    toName="hasta"
                    onFromChange={(event) => visit({ desde: event.target.value === '' ? null : event.target.value })}
                    onToChange={(event) => visit({ hasta: event.target.value === '' ? null : event.target.value })}
                    className="self-end"
                />
            </section>

            <section aria-label="Enviados" className="border-line bg-base-100 shadow-card overflow-hidden rounded-2xl border">
                {mails.length === 0 ? (
                    <div className="p-6">
                        <EmptyState icon={cilEnvelopeClosed} title={filtered ? 'Nada con estos filtros' : 'Todavía no sale ningún correo'}>
                            {filtered ? 'Prueba con otro estado, tipo o fechas.' : 'Aquí aparecerá cada correo que envíe la app.'}
                        </EmptyState>
                    </div>
                ) : (
                    <ul className="divide-line flex flex-col divide-y">
                        {mails.map((mail) => (
                            <li key={mail.uuid}>
                                <Link
                                    href={`/plataforma/correos/enviados/${mail.uuid}`}
                                    className="hover:bg-surface-2 flex flex-col gap-1 px-5 py-3.5 transition-colors"
                                >
                                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span className="truncate text-sm font-semibold">{mail.to}</span>
                                        {mail.otherRecipients > 0 ? <span className="text-ink-3 text-xs">+{mail.otherRecipients}</span> : null}
                                        <Badge tone={statusTone(mail.status)}>{mail.statusLabel}</Badge>
                                        <time dateTime={mail.createdAt} className="text-ink-3 ml-auto text-xs whitespace-nowrap">
                                            {dateTime(mail.createdAt)}
                                        </time>
                                    </span>
                                    <span className="text-ink-2 truncate text-[13px]">{mail.subject === '' ? '(sin asunto)' : mail.subject}</span>
                                    <span className="text-ink-3 flex flex-wrap gap-x-3 text-xs">
                                        <span>{mail.manual ? 'Escrito por el equipo' : mail.category}</span>
                                        {mail.opens > 0 ? <span>{count(mail.opens)} aperturas</span> : null}
                                        {mail.clicks > 0 ? <span>{count(mail.clicks)} clics</span> : null}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                <Pager page={page} hasMore={hasMore} onPage={(pagina) => visit({ pagina })} />
            </section>
        </PlatformLayout>
    );
}
