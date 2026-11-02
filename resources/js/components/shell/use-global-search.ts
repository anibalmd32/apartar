import { getJson } from '@/lib/json';
import { bo } from '@/lib/surfaces';
import { router } from '@inertiajs/react';
import { type KeyboardEvent, useEffect, useState } from 'react';

export interface SearchItem {
    readonly title: string;
    readonly detail: string | null;
    readonly url: string;
}

export interface SearchGroup {
    readonly key: string;
    readonly label: string;
    readonly items: readonly SearchItem[];
}

const SEARCH_URL = bo('/buscar');
const DEBOUNCE_MS = 250;

export function useGlobalSearch(onLeave: () => void) {
    const [term, setTerm] = useState('');
    const [groups, setGroups] = useState<readonly SearchGroup[]>([]);
    const [active, setActive] = useState(0);

    useEffect(() => {
        if (term.trim().length < 2) {
            return;
        }

        const timer = window.setTimeout(() => {
            void getJson<{ groups: SearchGroup[] }>(`${SEARCH_URL}?${new URLSearchParams({ q: term.trim() }).toString()}`)
                .then((body) => {
                    setGroups(body.groups);
                    setActive(0);
                })
                .catch(() => setGroups([]));
        }, DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [term]);

    const shown = term.trim().length < 2 ? [] : groups;
    const items = shown.flatMap((group) => group.items);

    const reset = () => {
        setTerm('');
        setGroups([]);
    };

    const go = (item: SearchItem | undefined) => {
        if (item !== undefined) {
            reset();
            onLeave();
            router.visit(item.url);
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((current) => (items.length === 0 ? 0 : (current + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            go(items[active]);
        }
    };

    return { term, setTerm, shown, active, setActive, reset, go, onKeyDown };
}
