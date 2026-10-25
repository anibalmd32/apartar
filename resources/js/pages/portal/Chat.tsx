import { Avatar } from '@/components/shell/avatar';
import { Icon } from '@/components/ui/icon';
import { ChatThreadView } from '@/features/chat/components/ChatThreadView';
import PortalLayout from '@/layouts/portal-layout';
import { type PortalChatProps } from '@/types/chat';
import { cilArrowLeft } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

export default function Chat({ businessName, thread }: PortalChatProps) {
    const { auth } = usePage<{ auth?: { portal?: { fullName: string } | null } }>().props;

    return (
        <PortalLayout title={`Chat · ${businessName}`} businessName={businessName} customerName={auth?.portal?.fullName ?? ''}>
            <Link
                href={route('portal.chat.index')}
                className="text-ink-3 hover:text-base-content flex items-center gap-1.5 self-start text-[13px] font-semibold"
            >
                <Icon icon={cilArrowLeft} size={14} />
                Tus conversaciones
            </Link>

            <section className="border-line bg-base-100 flex h-[calc(100dvh-250px)] min-h-[440px] flex-col overflow-hidden rounded-2xl border lg:h-[calc(100dvh-200px)]">
                <header className="border-line flex items-center gap-2.5 border-b px-5 py-3.5 lg:px-6">
                    <Avatar name={businessName} size={40} tone="accent" />
                    <h1 className="min-w-0 truncate text-[15px] font-bold">Escríbele a {businessName}</h1>
                </header>
                <ChatThreadView thread={thread} me="customer" className="flex-1" placeholder={`Escribe a ${businessName}…`} />
            </section>
        </PortalLayout>
    );
}
