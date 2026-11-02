import { SearchResults } from '@/components/shell/search-results';
import { useGlobalSearch } from '@/components/shell/use-global-search';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilSearch } from '@coreui/icons';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { useState } from 'react';

export function MobileSearch({ className }: { className?: string }) {
    const [open, setOpen] = useState(false);
    const search = useGlobalSearch(() => setOpen(false));

    const close = () => {
        setOpen(false);
        search.reset();
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label="Buscar"
                className={cn('text-ink-2 hover:bg-surface-2 flex size-11 cursor-pointer items-center justify-center rounded-[10px]', className)}
            >
                <Icon icon={cilSearch} size={20} />
            </button>

            <Dialog open={open} onClose={close} className="relative z-50">
                <DialogPanel className="bg-base-100 fixed inset-0 flex flex-col pt-[env(safe-area-inset-top)]">
                    <DialogTitle className="sr-only">Buscar</DialogTitle>
                    <div className="border-line flex items-center gap-2 border-b px-4 py-2">
                        <label className="bg-surface-2 flex h-11 min-w-0 flex-1 items-center gap-2 rounded-[10px] px-3">
                            <Icon icon={cilSearch} className="text-ink-3" />
                            <input
                                autoFocus
                                data-autofocus
                                type="search"
                                enterKeyHint="search"
                                aria-label="Buscar cliente, código o producto"
                                placeholder="Cliente, código o producto"
                                className="min-w-0 flex-1 bg-transparent text-base outline-none"
                                value={search.term}
                                onChange={(event) => search.setTerm(event.target.value)}
                                onKeyDown={search.onKeyDown}
                            />
                        </label>
                        <button type="button" onClick={close} className="btn btn-ghost text-primary h-11 min-h-11 px-2 text-[15px] font-semibold">
                            Cancelar
                        </button>
                    </div>

                    <div className="flex flex-1 flex-col gap-4 overflow-y-auto overscroll-contain px-4 pt-4 pb-[calc(env(safe-area-inset-bottom)+1rem)]">
                        {search.term.trim().length < 2 ? (
                            <p className="text-ink-3 text-sm">
                                Escribe al menos dos letras: el nombre o la cédula del cliente, el código del apartado o un producto.
                            </p>
                        ) : null}
                        <SearchResults
                            term={search.term}
                            groups={search.shown}
                            active={search.active}
                            onHover={search.setActive}
                            onPick={search.go}
                            itemClassName="min-h-12 justify-center"
                        />
                    </div>
                </DialogPanel>
            </Dialog>
        </>
    );
}
