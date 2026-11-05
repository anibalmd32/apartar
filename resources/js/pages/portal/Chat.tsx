import { RateText } from '@/components/shell/rate-strip';
import { ChatHeader } from '@/features/chat/components/ChatHeader';
import { ChatThreadView } from '@/features/chat/components/ChatThreadView';
import PortalLayout from '@/layouts/portal-layout';
import { type PortalChatProps } from '@/types/chat';
import { usePage } from '@inertiajs/react';

export default function Chat({ businessName, thread }: PortalChatProps) {
    const { auth } = usePage<{ auth?: { portal?: { fullName: string } | null } }>().props;

    return (
        <PortalLayout title={`Chat · ${businessName}`} businessName={businessName} customerName={auth?.portal?.fullName ?? ''} immersive>
            <section className="bg-base-100 lg:border-line flex h-dvh flex-col overflow-hidden lg:h-[calc(100dvh-150px)] lg:min-h-[480px] lg:rounded-2xl lg:border">
                <ChatHeader
                    name={businessName}
                    heading="h1"
                    backHref={route('portal.chat.index')}
                    backLabel="Volver a tus conversaciones"
                    backOnDesktop
                    subtitle={<RateText className="text-xs lg:hidden" />}
                />
                <ChatThreadView thread={thread} me="customer" className="flex-1" placeholder={`Escribe a ${businessName}…`} />
            </section>
        </PortalLayout>
    );
}
