import { type ActiveFilter, ActiveFilterChips } from '@/components/ui/active-filter-chips';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { cn } from '@/lib/utils';
import { cilFilter } from '@coreui/icons';
import { type ReactNode, useState } from 'react';

interface FilterSheetProps {
    count: number;
    onOpen: () => void;
    onApply: () => void;
    onClear: () => void;
    children: ReactNode;
}

export function FilterSheet({ count, onOpen, onApply, onClear, children }: FilterSheetProps) {
    const [open, setOpen] = useState(false);
    const finish = (action: () => void) => () => {
        action();
        setOpen(false);
    };

    return (
        <>
            <button
                type="button"
                aria-haspopup="dialog"
                aria-label={count === 0 ? 'Filtros' : `Filtros, ${count} ${count === 1 ? 'aplicado' : 'aplicados'}`}
                className={cn('btn shrink-0 lg:hidden', count > 0 && 'border-primary')}
                onClick={() => {
                    onOpen();
                    setOpen(true);
                }}
            >
                <Icon icon={cilFilter} />
                Filtros
                {count > 0 ? <span className="badge badge-count badge-solid badge-primary">{count}</span> : null}
            </button>
            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title="Filtros"
                size="md"
                fillActions
                actions={
                    <>
                        <Button variant="secondary" onClick={finish(onClear)}>
                            Limpiar
                        </Button>
                        <Button onClick={finish(onApply)}>Aplicar</Button>
                    </>
                }
            >
                {children}
            </Modal>
        </>
    );
}

interface FilterBarProps extends Omit<FilterSheetProps, 'count' | 'children'> {
    search?: ReactNode;
    quick?: ReactNode;
    inline: ReactNode;
    sheet: ReactNode;
    chips: readonly ActiveFilter[];
    className?: string;
}

export function FilterBar({ search, quick, inline, sheet, chips, className, ...sheetProps }: FilterBarProps) {
    return (
        <>
            <div className={cn('flex flex-wrap items-center gap-2 max-lg:gap-y-3', className)}>
                {search}
                {quick ? <div className="max-lg:order-first max-lg:w-full lg:contents">{quick}</div> : null}
                <div className="max-lg:hidden lg:contents">{inline}</div>
                <FilterSheet count={chips.length} {...sheetProps}>
                    {sheet}
                </FilterSheet>
            </div>
            <ActiveFilterChips filters={chips} />
        </>
    );
}
