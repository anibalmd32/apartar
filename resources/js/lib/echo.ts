import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

type EchoClient = Echo<'reverb'>;

let client: EchoClient | null | undefined;

export function echo(): EchoClient | null {
    if (client !== undefined) {
        return client;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

    if (typeof window === 'undefined' || key === undefined || key === '') {
        client = null;

        return client;
    }

    const scheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? 'https';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80));

    try {
        client = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: (import.meta.env.VITE_REVERB_HOST as string | undefined) ?? window.location.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            Pusher,
        });
    } catch {
        client = null;
    }

    return client;
}

export function resetEchoForTests(): void {
    client = undefined;
}
