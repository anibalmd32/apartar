import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { type ConversationRow } from '@/types/chat';
import { Link } from '@inertiajs/react';

const TIME = new Intl.DateTimeFormat('es-VE', { hour: '2-digit', minute: '2-digit' });
const DAY = new Intl.DateTimeFormat('es-VE', { day: '2-digit', month: '2-digit' });

function when(iso: string): string {
    const date = new Date(iso);

    return date.toDateString() === new Date().toDateString() ? TIME.format(date) : DAY.format(date);
}

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
                                'flex items-center gap-2.5 px-4 py-3 transition-colors',
                                activeId === thread.id ? 'bg-primary-soft' : 'hover:bg-surface-2',
                            )}
                        >
                            <Avatar name={thread.customerName} size={40} tone="accent" />
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className={cn('truncate text-sm', unread ? 'font-bold' : 'font-semibold')}>{thread.customerName}</span>
                                <span className={cn('truncate text-xs', unread ? 'text-ink-2' : 'text-ink-3')}>
                                    {thread.customerDocument} · {thread.excerpt}
                                </span>
                            </span>
                            <span className="flex shrink-0 flex-col items-end gap-1">
                                {thread.lastMessageAt !== null ? <span className="text-ink-3 text-[11px]">{when(thread.lastMessageAt)}</span> : null}
                                {unread ? (
                                    <Badge tone="primary" count>
                                        {thread.unread}
                                        <span className="sr-only"> sin leer</span>
                                    </Badge>
                                ) : null}
                            </span>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
