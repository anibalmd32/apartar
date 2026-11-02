type PopGuard = (event: PopStateEvent) => boolean;

const guards = new Set<PopGuard>();

export function guardHistoryPops(target: Window = window): () => void {
    const listener = (event: PopStateEvent) => {
        for (const guard of guards) {
            if (guard(event)) {
                event.stopImmediatePropagation();

                return;
            }
        }
    };

    target.addEventListener('popstate', listener);

    return () => target.removeEventListener('popstate', listener);
}

export function onHistoryPop(guard: PopGuard): () => void {
    guards.add(guard);

    return () => {
        guards.delete(guard);
    };
}
