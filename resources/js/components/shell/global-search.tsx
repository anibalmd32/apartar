import { SearchResults } from '@/components/shell/search-results';
import { useGlobalSearch } from '@/components/shell/use-global-search';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { cn } from '@/lib/utils';
import { cilSearch } from '@coreui/icons';
import { useEffect, useState } from 'react';

export function GlobalSearch({ className }: { className?: string }) {
    const [open, setOpen] = useState(false);
    const search = useGlobalSearch(() => setOpen(false));

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

    const close = () => {
        setOpen(false);
        search.reset();
    };

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
                    enterKeyHint="search"
                    aria-label="Buscar cliente, código o producto"
                    placeholder="Buscar cliente, código o producto…"
                    className="input w-full"
                    value={search.term}
                    onChange={(event) => search.setTerm(event.target.value)}
                    onKeyDown={search.onKeyDown}
                />

                <SearchResults term={search.term} groups={search.shown} active={search.active} onHover={search.setActive} onPick={search.go} />
            </Modal>
        </>
    );
}
