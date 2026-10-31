import { echo } from '@/lib/echo';
import { toast } from '@/lib/toast';
import { type MessageSenderType } from '@/types/chat';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';

interface IncomingMessage {
    readonly conversationId: number;
    readonly sender: MessageSenderType;
    readonly senderName: string;
    readonly body: string;
}

interface ChatNotificationsOptions {
    channels: readonly string[];
    me: MessageSenderType;
    reloadProps: readonly string[];
    chatPath: string;
}

const PREVIEW_LENGTH = 60;

export function useChatNotifications({ channels, me, reloadProps, chatPath }: ChatNotificationsOptions) {
    const key = channels.join('|');

    useEffect(() => {
        const client = key === '' ? null : echo();

        if (client === null) {
            return;
        }

        const onMessage = (message: IncomingMessage) => {
            if (message.sender === me) {
                return;
            }

            router.reload({ only: [...reloadProps] });

            if (!window.location.pathname.startsWith(chatPath)) {
                const preview = message.body.length > PREVIEW_LENGTH ? `${message.body.slice(0, PREVIEW_LENGTH)}…` : message.body;
                toast.info(`Nuevo mensaje de ${message.senderName}: ${preview}`);
            }
        };

        const subscribed = key.split('|');

        for (const channel of subscribed) {
            client.private(channel).listen('MessageSent', onMessage);
        }

        return () => {
            for (const channel of subscribed) {
                client.private(channel).stopListening('MessageSent', onMessage);
            }
        };
    }, [key, me, reloadProps, chatPath]);
}
