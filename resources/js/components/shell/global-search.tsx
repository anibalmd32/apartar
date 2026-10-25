import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { getJson } from '@/lib/json';
import { cn } from '@/lib/utils';
import { cilSearch } from '@coreui/icons';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface SearchItem {
    readonly title: string;
    readonly detail: string | null;
    readonly url: string;
}

interface SearchGroup {
    readonly key: string;
    readonly label: string;
    readonly items: readonly SearchItem[];
}

const SEARCH_URL = '/backoffice/buscar';
const DEBOUNCE_MS = 250;

export function GlobalSearch({ className }: { className?: string }) {
    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState('');
    const [groups, setGroups] = useState<readonly SearchGroup[]>([]);
    const [active, setActive] = useState(0);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setOpen(true);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

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

    const close = () => {
        setOpen(false);
        setTerm('');
        setGroups([]);
    };

    const go = (item: SearchItem | undefined) => {
        if (item !== undefined) {
            close();
            router.visit(item.url);
        }
    };

    let index = -1;

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className={cn(
                    'bg-surface-2 text-ink-3 hover:text-base-content flex h-10 w-[360px] max-w-full cursor-pointer items-center gap-2.5 rounded-[10px] px-3 text-left text-sm',
                    className,
                )}
            >
                <Icon icon={cilSearch} />
                <span className="flex-1 truncate">Buscar cliente, código o producto…</span>
                <kbd className="bg-base-100 border-line rounded-md border px-1.5 py-0.5 text-[11px] font-semibold">Ctrl K</kbd>
            </button>

            <Modal open={open} onClose={close} title="Buscar" size="md">
                <input
                    autoFocus
                    type="search"
                    aria-label="Buscar cliente, código o producto"
                    placeholder="Buscar cliente, código o producto…"
                    className="input w-full"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                            event.preventDefault();
                            setActive((current) =>
                                items.length === 0 ? 0 : (current + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length,
                            );
                        } else if (event.key === 'Enter') {
                            event.preventDefault();
                            go(items[active]);
                        }
                    }}
                />

                {term.trim().length >= 2 && shown.length === 0 ? <p className="text-ink-3 text-[13px]">Nada coincide con «{term.trim()}».</p> : null}

                {shown.map((group) => (
                    <section key={group.key} aria-label={group.label} className="flex flex-col gap-1">
                        <h3 className="text-eyebrow text-ink-3">{group.label}</h3>
                        <ul className="flex flex-col">
                            {group.items.map((item) => {
                                index += 1;
                                const position = index;

                                return (
                                    <li key={item.url}>
                                        <button
                                            type="button"
                                            onClick={() => go(item)}
                                            onMouseEnter={() => setActive(position)}
                                            className={cn(
                                                'flex w-full cursor-pointer flex-col rounded-lg px-3 py-2 text-left',
                                                position === active ? 'bg-primary-soft' : 'hover:bg-surface-2',
                                            )}
                                        >
                                            <span className="text-sm font-semibold">{item.title}</span>
                                            {item.detail ? <span className="text-ink-3 text-xs">{item.detail}</span> : null}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                ))}
            </Modal>
        </>
    );
}
