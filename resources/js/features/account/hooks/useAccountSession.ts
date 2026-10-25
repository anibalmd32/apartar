import { type PortalSessionView } from '@/types/portal';
import { usePage } from '@inertiajs/react';

export function useAccountSession() {
    const session = usePage<{ auth?: { portal?: PortalSessionView | null } }>().props.auth?.portal ?? null;

    return {
        session,
        isAccount: session?.mode === 'account',
        isScoped: session?.mode === 'scoped',
    };
}
