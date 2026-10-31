import { echo } from '@/lib/echo';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';

export const INBOX_REFRESH_MS = 15_000;

const RELOAD_DEBOUNCE_MS = 400;

const INBOX_PROPS = ['threads', 'nextPageUrl', 'teamMember'];

export function useChatInbox(channel: string) {
    useEffect(() => {
        let pending: number | undefined;

        const refresh = () => {
            window.clearTimeout(pending);
            pending = window.setTimeout(() => router.reload({ only: INBOX_PROPS }), RELOAD_DEBOUNCE_MS);
        };

        const refreshWhenVisible = () => {
            if (document.visibilityState === 'visible') {
                refresh();
            }
        };

        const client = channel === '' ? null : echo();
        client?.private(channel).listen('MessageSent', refresh);

        const timer = window.setInterval(refreshWhenVisible, INBOX_REFRESH_MS);
        document.addEventListener('visibilitychange', refreshWhenVisible);

        return () => {
            window.clearTimeout(pending);
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', refreshWhenVisible);
            client?.leave(channel);
        };
    }, [channel]);
}
