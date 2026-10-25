import { echo } from '@/lib/echo';
import { postJson } from '@/lib/json';
import { type MessageSenderType } from '@/types/chat';
import { useCallback, useEffect, useRef, useState } from 'react';

export const TYPING_THROTTLE_MS = 2_000;

export const TYPING_EXPIRES_MS = 4_000;

interface Typing {
    readonly sender: MessageSenderType;
    readonly name: string;
}

export function useTypingIndicator(channel: string | null, typingUrl: string, me: MessageSenderType, live: boolean) {
    const [typing, setTyping] = useState<string | null>(null);
    const lastSent = useRef(0);
    const expiry = useRef<number | undefined>(undefined);

    useEffect(() => {
        const client = channel === null || !live ? null : echo();

        if (client === null || channel === null) {
            return;
        }

        client.private(channel).listen('UserTyping', (event: Typing) => {
            if (event.sender === me) {
                return;
            }

            setTyping(event.name);
            window.clearTimeout(expiry.current);
            expiry.current = window.setTimeout(() => setTyping(null), TYPING_EXPIRES_MS);
        });

        return () => {
            window.clearTimeout(expiry.current);
            client.private(channel).stopListening('UserTyping');
        };
    }, [channel, live, me]);

    const notify = useCallback(() => {
        const now = Date.now();

        if (!live || channel === null || now - lastSent.current < TYPING_THROTTLE_MS) {
            return;
        }

        lastSent.current = now;
        void postJson(typingUrl, {}).catch(() => undefined);
    }, [channel, live, typingUrl]);

    return { typing, notify };
}
