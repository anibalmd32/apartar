import { router } from '@inertiajs/react';

type Navigate = (href: string) => void;

export function leavesOrigin(url: URL, origin: string = window.location.origin): boolean {
    return url.origin !== origin;
}

export function guardCrossOriginVisits(navigate: Navigate = (href) => window.location.assign(href)): () => void {
    return router.on('before', (event) => {
        const visit = event.detail.visit;

        if (visit.method !== 'get' || !leavesOrigin(visit.url)) {
            return;
        }

        navigate(visit.url.href);

        return false;
    });
}
