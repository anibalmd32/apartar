import { EmptyState } from '@/components/ui/empty-state';
import { FilterTabs } from '@/components/ui/filter-tabs';
import { Icon } from '@/components/ui/icon';
import { Pager } from '@/features/platform/components/Pager';
import { shortDate } from '@/features/platform/mail/format';
import { MailTabs } from '@/features/platform/mail/MailTabs';
import PlatformLayout from '@/layouts/platform-layout';
import { cn } from '@/lib/utils';
import { type MailThreadRow } from '@/types/mail';
import { cilInbox, cilSearch } from '@coreui/icons';
import { Link, router, usePoll } from '@inertiajs/react';
import { useState } from 'react';

interface InboxFilters {
    filtro: 'no-leidos' | null;
    q: string | null;
}

interface InboxProps {
    threads: readonly MailThreadRow[];
    hasMore: boolean;
    page: number;
    filters: InboxFilters;
    unreadThreads: number;
}

export const INBOX_POLL_MS = 60_000;

export default function MailInboxPage({ threads, hasMore, page, filters, unreadThreads }: InboxProps) {
    const [search, setSearch] = useState(filters.q ?? '');
    const visit = (changes: Partial<Record<keyof InboxFilters | 'pagina', string | number | null>>) =>
        router.get('/plataforma/correos/bandeja', { ...filters, pagina: 1, ...changes }, { preserveState: true, replace: true });

    usePoll(INBOX_POLL_MS, { only: ['threads', 'hasMore', 'unreadThreads', 'platformOperator'] });

    return (
        <PlatformLayout title="Bandeja" description="Lo que llega a cualquier dirección @apartar.app. Las respuestas salen desde info@.">
            <MailTabs />

            <section aria-label="Filtros" className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <FilterTabs
                    label="Mostrar"
                    tabs={[
                        { value: null, label: 'Todas' },
                        { value: 'no-leidos' as const, label: 'Sin leer', count: unreadThreads },
                    ]}
                    value={filters.filtro}
                    onChange={(filtro) => visit({ filtro })}
                />
                <form
                    role="search"
                    className="sm:w-[320px]"
                    onSubmit={(event) => {
                        event.preventDefault();
                        visit({ q: search.trim() === '' ? null : search.trim() });
                    }}
                >
                    <label className="input w-full">
                        <Icon icon={cilSearch} className="text-ink-3" />
                        <input
                            type="search"
                            aria-label="Buscar en la bandeja"
                            className="grow"
                            placeholder="Remitente, asunto o texto"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </label>
                </form>
            </section>

            <section aria-label="Conversaciones" className="border-line bg-base-100 shadow-card overflow-hidden rounded-2xl border">
                {threads.length === 0 ? (
                    <div className="p-6">
                        <EmptyState icon={cilInbox} title={filters.filtro !== null || filters.q !== null ? 'Nada por aquí' : 'La bandeja está vacía'}>
                            {filters.filtro !== null || filters.q !== null
                                ? 'Prueba quitando los filtros.'
                                : 'Cuando alguien escriba a una dirección @apartar.app, aparecerá aquí.'}
                        </EmptyState>
                    </div>
                ) : (
                    <ul className="divide-line flex flex-col divide-y">
                        {threads.map((thread) => {
                            const unread = thread.unread > 0;

                            return (
                                <li key={thread.id}>
                                    <Link
                                        href={`/plataforma/correos/bandeja/${thread.id}`}
                                        className={cn('hover:bg-surface-2 flex gap-3 px-5 py-3.5 transition-colors', unread && 'bg-primary-soft/40')}
                                    >
                                        <span
                                            className={cn('mt-1.5 size-2 shrink-0 rounded-full', unread ? 'bg-primary' : 'bg-transparent')}
                                            aria-hidden="true"
                                        />
                                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                            <span className="flex items-baseline gap-2">
                                                <span className={cn('truncate text-sm', unread ? 'font-bold' : 'font-medium')}>
                                                    {thread.counterpart}
                                                </span>
                                                {thread.messages > 1 ? <span className="text-ink-3 text-xs">{thread.messages}</span> : null}
                                                <time dateTime={thread.lastMessageAt} className="text-ink-3 ml-auto shrink-0 text-xs">
                                                    {shortDate(thread.lastMessageAt)}
                                                </time>
                                            </span>
                                            <span className={cn('truncate text-[13px]', unread ? 'font-bold' : 'text-ink-2')}>{thread.subject}</span>
                                            <span className="text-ink-3 truncate text-xs">{thread.snippet}</span>
                                            {unread ? <span className="sr-only">Sin leer</span> : null}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
                <Pager page={page} hasMore={hasMore} onPage={(pagina) => visit({ pagina })} />
            </section>
        </PlatformLayout>
    );
}
