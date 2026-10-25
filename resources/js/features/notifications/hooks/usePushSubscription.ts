import { postJson } from '@/lib/json';
import { useCallback, useEffect, useState } from 'react';

export const PUSH_DECLINED_KEY = 'apartar:push-declined';

export type PushSubscriptionState = 'unsupported' | 'unavailable' | 'denied' | 'idle' | 'subscribing' | 'subscribed';

export function usePushSubscription(publicKey: string | null, subscribeUrl: string) {
    const [state, setState] = useState<PushSubscriptionState>(() => initialState(publicKey));

    useEffect(() => {
        if (state !== 'idle') {
            return;
        }

        void navigator.serviceWorker.getRegistration('/sw.js').then(async (registration) => {
            const existing = await registration?.pushManager.getSubscription();

            if (existing) {
                setState('subscribed');
            }
        });
    }, [state]);

    const subscribe = useCallback(async () => {
        if (publicKey === null || state === 'unsupported') {
            return;
        }

        setState('subscribing');

        const permission = await Notification.requestPermission();

        if (permission !== 'granted') {
            window.localStorage.setItem(PUSH_DECLINED_KEY, '1');
            setState('denied');

            return;
        }

        try {
            const registration = await navigator.serviceWorker.register('/sw.js');
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey),
            });
            const json = subscription.toJSON();

            await postJson(subscribeUrl, { endpoint: json.endpoint, keys: json.keys, contentEncoding: 'aes128gcm' });
            setState('subscribed');
        } catch {
            setState('idle');
        }
    }, [publicKey, state, subscribeUrl]);

    return { state, subscribe };
}

function initialState(publicKey: string | null): PushSubscriptionState {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        return 'unsupported';
    }

    if (publicKey === null || publicKey === '') {
        return 'unavailable';
    }

    if (Notification.permission === 'denied' || window.localStorage.getItem(PUSH_DECLINED_KEY) === '1') {
        return 'denied';
    }

    return 'idle';
}

function urlBase64ToUint8Array(base64: string): Uint8Array {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = window.atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));

    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}
