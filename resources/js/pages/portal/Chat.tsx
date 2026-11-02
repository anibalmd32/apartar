import { Avatar } from '@/components/shell/avatar';
import { BackLink } from '@/components/ui/back-link';
import { ChatThreadView } from '@/features/chat/components/ChatThreadView';
import PortalLayout from '@/layouts/portal-layout';
import { type PortalChatProps } from '@/types/chat';
import { usePage } from '@inertiajs/react';

export default function Chat({ businessName, thread }: PortalChatProps) {
    const { auth } = usePage<{ auth?: { portal?: { fullName: string } | null } }>().props;

    return (
        <PortalLayout title={`Chat · ${businessName}`} businessName={businessName} customerName={auth?.portal?.fullName ?? ''}>
            <BackLink href={route('portal.chat.index')}>Tus conversaciones</BackLink>

            <section className="border-line bg-base-100 flex h-[calc(100dvh-190px)] min-h-[480px] flex-col overflow-hidden border-y max-lg:-mx-4 lg:h-[calc(100dvh-150px)] lg:rounded-2xl lg:border">
                <header className="border-line flex items-center gap-2.5 border-b px-4 py-3.5 lg:px-6">
                    <Avatar name={businessName} size={40} tone="accent" />
                    <h1 className="min-w-0 truncate text-[15px] font-bold">Escríbele a {businessName}</h1>
                </header>
                <ChatThreadView thread={thread} me="customer" className="flex-1" placeholder={`Escribe a ${businessName}…`} />
            </section>
        </PortalLayout>
    );
}
