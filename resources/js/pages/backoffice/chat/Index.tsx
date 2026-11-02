import { Alert } from '@/components/ui/alert';
import { FilterTabs } from '@/components/ui/filter-tabs';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { ChatHeader } from '@/features/chat/components/ChatHeader';
import { ChatThreadView } from '@/features/chat/components/ChatThreadView';
import { ConversationList } from '@/features/chat/components/ConversationList';
import { EmptyInbox } from '@/features/chat/components/EmptyInbox';
import { ThreadContextHeader, ThreadContextPanel } from '@/features/chat/components/ThreadContextHeader';
import { useChatInbox } from '@/features/chat/hooks/useChatInbox';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { bo } from '@/lib/surfaces';
import { cn } from '@/lib/utils';
import { type BackofficeSharedData } from '@/types/backoffice';
import { type BackofficeChatProps } from '@/types/chat';
import { cilChatBubble, cilSearch } from '@coreui/icons';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function ChatIndex({ threads, nextPageUrl, search, active, historyDays, unreadOnly = false, inboxChannel = '' }: BackofficeChatProps) {
    const [query, setQuery] = useState(search);
    useChatInbox(inboxChannel);
    const { teamMember } = usePage<BackofficeSharedData>().props;
    const filter = (onlyUnread: boolean) =>
        router.get(
            bo('/chat'),
            { ...(search !== '' ? { q: search } : {}), ...(onlyUnread ? { no_leidos: 1 } : {}) },
            { preserveState: true, replace: true },
        );

    return (
        <BackofficeLayout title="Chat" description="Las conversaciones con tus clientes." immersive={active !== null}>
            <div
                className={cn(
                    'border-line bg-base-100 grid grid-cols-1 overflow-hidden rounded-2xl border lg:h-[calc(100dvh-170px)] lg:min-h-[600px] lg:grid-cols-[300px_minmax(0,1fr)]',
                    active !== null && 'max-lg:rounded-none max-lg:border-0 xl:grid-cols-[320px_minmax(0,1fr)_280px]',
                )}
            >
                <section
                    aria-label="Conversaciones"
                    className={cn('lg:border-line flex min-h-0 flex-col lg:border-r', active !== null && 'max-lg:hidden')}
                >
                    <form
                        role="search"
                        className="p-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(bo('/chat'), query.trim() === '' ? {} : { q: query.trim() }, { preserveState: true, replace: true });
                        }}
                    >
                        <label className="bg-surface-2 focus-within:outline-primary flex h-11 items-center gap-2.5 rounded-xl px-3 focus-within:outline-2">
                            <Icon icon={cilSearch} className="text-ink-3" />
                            <input
                                type="search"
                                className="placeholder:text-ink-3 min-w-0 flex-1 bg-transparent text-sm outline-none"
                                aria-label="Buscar conversaciones"
                                placeholder="Nombre, cédula, teléfono o mensaje"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                            />
                        </label>
                    </form>

                    <FilterTabs
                        label="Mostrar"
                        className="px-4 pb-3"
                        value={unreadOnly ? 'unread' : 'all'}
                        onChange={(value) => filter(value === 'unread')}
                        tabs={[
                            { value: 'all', label: 'Todos' },
                            { value: 'unread', label: 'No leídos', count: teamMember?.unreadChats ?? null },
                        ]}
                    />

                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {threads.length === 0 ? (
                            <EmptyInbox unreadOnly={unreadOnly} searching={search !== ''} />
                        ) : (
                            <ConversationList threads={threads} activeId={active?.id ?? null} />
                        )}

                        {nextPageUrl !== null ? (
                            <div className="p-4">
                                <Link href={nextPageUrl} className="btn btn-sm btn-block">
                                    Ver más
                                </Link>
                            </div>
                        ) : null}
                    </div>
                </section>

                <section
                    aria-label="Conversación abierta"
                    className={cn('flex min-h-0 flex-col', active === null ? 'max-lg:hidden' : 'max-lg:h-dvh max-lg:overflow-hidden')}
                >
                    {active === null ? (
                        <div className="text-ink-3 flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center text-sm">
                            <IconChip icon={cilChatBubble} size={48} />
                            Elige una conversación.
                        </div>
                    ) : (
                        <>
                            <ChatHeader name={active.context.customerName} backHref={bo('/chat')} backLabel="Volver a las conversaciones">
                                <Link href={active.context.profileUrl} className="btn btn-sm max-sm:hidden">
                                    Ver perfil
                                </Link>
                            </ChatHeader>

                            <div className="flex flex-col gap-2 px-4 pt-3 pb-1 empty:hidden xl:px-5">
                                <ThreadContextHeader context={active.context} className="xl:hidden" />
                                {active.hiddenHistory && historyDays !== null ? (
                                    <Alert tone="info">
                                        Ves los últimos {historyDays} días. Los mensajes anteriores no se han borrado: se ven con un plan superior.
                                    </Alert>
                                ) : null}
                            </div>

                            <ChatThreadView key={active.id} thread={active} me="team" placeholder="Responde a tu cliente" className="flex-1" />
                        </>
                    )}
                </section>

                {active !== null ? <ThreadContextPanel context={active.context} className="border-line hidden border-l xl:flex" /> : null}
            </div>
        </BackofficeLayout>
    );
}
