import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { listTime } from '@/features/chat/lib/list-time';
import { formatDocumentNumber } from '@/lib/identity-format';
import { cn } from '@/lib/utils';
import { type ConversationRow } from '@/types/chat';
import { Link } from '@inertiajs/react';

export function ConversationList({ threads, activeId }: { threads: readonly ConversationRow[]; activeId: number | null }) {
    return (
        <ul className="flex flex-col">
            {threads.map((thread) => {
                const unread = thread.unread > 0;

                return (
                    <li key={thread.id}>
                        <Link
                            href={thread.url}
                            aria-current={activeId === thread.id ? 'page' : undefined}
                            className={cn(
                                'flex items-center gap-3 px-4 py-3 transition-colors',
                                activeId === thread.id ? 'bg-primary-soft' : 'hover:bg-surface-2',
                            )}
                        >
                            <Avatar name={thread.customerName} size={44} tone="accent" />
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="flex items-baseline gap-2">
                                    <span className={cn('min-w-0 flex-1 truncate text-[15px]', unread ? 'font-bold' : 'font-semibold')}>
                                        {thread.customerName}
                                    </span>
                                    {thread.lastMessageAt !== null ? (
                                        <span className={cn('shrink-0 text-xs', unread ? 'text-primary font-semibold' : 'text-ink-3')}>
                                            {listTime(thread.lastMessageAt)}
                                        </span>
                                    ) : null}
                                </span>
                                <span className="flex items-center gap-2">
                                    <span className={cn('min-w-0 flex-1 truncate text-[13px]', unread ? 'text-base-content' : 'text-ink-3')}>
                                        {thread.customerDocument === '' ? null : (
                                            <>
                                                <span className="whitespace-nowrap">{formatDocumentNumber(thread.customerDocument)}</span>
                                                {' · '}
                                            </>
                                        )}
                                        {thread.excerpt}
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
    );
}
