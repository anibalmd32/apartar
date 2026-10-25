import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { IconChip } from '@/components/ui/icon-chip';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import PortalLayout from '@/layouts/portal-layout';
import { type PortalChatIndexProps } from '@/types/chat';
import { cilChatBubble } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

export default function ChatIndex({ threads }: PortalChatIndexProps) {
    const { auth } = usePage<{ auth?: { portal?: { fullName: string } | null } }>().props;

    return (
        <PortalLayout title="Chat" businessName="Chat" customerName={auth?.portal?.fullName ?? ''}>
            <h1 className="text-[24px] leading-tight font-extrabold tracking-[-0.6px] lg:text-[30px]">Tus conversaciones</h1>

            {threads.length === 0 ? (
                <p className="border-line bg-base-100 text-ink-2 rounded-2xl border p-6 text-sm">
                    Cuando apartes en un negocio, podrás escribirle desde aquí.
                </p>
            ) : (
                <div className="border-line bg-base-100 overflow-hidden rounded-2xl border lg:grid lg:min-h-[480px] lg:grid-cols-[320px_minmax(0,1fr)]">
                    <ul className="divide-line lg:border-line divide-y py-1 lg:border-r">
                        {threads.map((thread) => (
                            <li key={thread.url}>
                                <Link href={thread.url} className="hover:bg-surface-2 flex items-center gap-2.5 px-4 py-3 transition-colors">
                                    <Avatar name={thread.businessName} size={40} tone="accent" />
                                    <span className="min-w-0 flex-1 truncate text-sm font-bold">{thread.businessName}</span>
                                    <span className="flex shrink-0 flex-col items-end gap-1">
                                        {thread.lastMessageAt !== null ? (
                                            <span className="text-ink-3 text-[11px]">{formatDay(thread.lastMessageAt.slice(0, 10))}</span>
                                        ) : null}
                                        {thread.unread > 0 ? (
                                            <Badge tone="primary" count>
                                                {thread.unread}
                                            </Badge>
                                        ) : null}
                                    </span>
                                </Link>
                            </li>
                        ))}
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
