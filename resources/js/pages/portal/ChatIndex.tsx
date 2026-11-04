import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { IconChip } from '@/components/ui/icon-chip';
import { listTime } from '@/features/chat/lib/list-time';
import PortalLayout from '@/layouts/portal-layout';
import { cn } from '@/lib/utils';
import { type PortalChatIndexProps } from '@/types/chat';
import { cilChatBubble } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

export default function ChatIndex({ threads }: PortalChatIndexProps) {
    const { auth } = usePage<{ auth?: { portal?: { fullName: string; businessName: string | null } | null } }>().props;

    return (
        <PortalLayout title="Chat" businessName={auth?.portal?.businessName ?? 'Mi cuenta'} customerName={auth?.portal?.fullName ?? ''}>
            <h1 className="tracking-heading text-[24px] leading-tight font-extrabold max-lg:sr-only lg:text-[30px]">Tus conversaciones</h1>

            {threads.length === 0 ? (
                <div className="border-line bg-base-100 flex flex-col items-center gap-3 rounded-2xl border px-6 py-10 text-center">
                    <IconChip icon={cilChatBubble} size={48} />
                    <p className="font-bold">Aún no tienes conversaciones</p>
                    <p className="text-ink-2 max-w-sm text-sm">
                        Cuando apartes en un negocio podrás escribirle desde aquí, y sus respuestas te llegarán a esta misma lista.
                    </p>
                </div>
            ) : (
                <div className="border-line bg-base-100 overflow-hidden rounded-2xl border lg:grid lg:min-h-[480px] lg:grid-cols-[320px_minmax(0,1fr)]">
                    <ul className="divide-line lg:border-line divide-y py-1 lg:border-r">
                        {threads.map((thread) => {
                            const unread = thread.unread > 0;

                            return (
                                <li key={thread.url}>
                                    <Link href={thread.url} className="hover:bg-surface-2 flex items-center gap-3 px-4 py-3 transition-colors">
                                        <Avatar name={thread.businessName} size={44} tone="accent" />
                                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                            <span className="flex items-baseline gap-2">
                                                <span className={cn('min-w-0 flex-1 truncate text-[15px]', unread ? 'font-bold' : 'font-semibold')}>
                                                    {thread.businessName}
                                                </span>
                                                {thread.lastMessageAt !== null ? (
                                                    <span className={cn('shrink-0 text-xs', unread ? 'text-primary font-semibold' : 'text-ink-3')}>
                                                        {listTime(thread.lastMessageAt)}
                                                    </span>
                                                ) : null}
                                            </span>
                                            <span className="flex items-center gap-2">
                                                <span
                                                    className={cn('min-w-0 flex-1 truncate text-[13px]', unread ? 'text-base-content' : 'text-ink-3')}
                                                >
                                                    {thread.excerpt === '' ? 'Escríbele cuando quieras' : thread.excerpt}
                                                </span>
                                                {unread ? (
                                                    <Badge tone="primary" count>
                                                        {thread.unread}
                                                        <span className="sr-only"> sin leer</span>
                                                    </Badge>
                                                ) : null}
                                            </span>
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>

                    <div className="text-ink-3 hidden flex-col items-center justify-center gap-3 p-8 text-center text-sm lg:flex">
                        <IconChip icon={cilChatBubble} size={48} />
                        Elige un negocio para ver la conversación.
                    </div>
                </div>
            )}
        </PortalLayout>
    );
}
