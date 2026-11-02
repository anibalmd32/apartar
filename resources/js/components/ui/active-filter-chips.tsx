import { Icon } from '@/components/ui/icon';
import { ScrollRow } from '@/components/ui/scroll-row';
import { cn } from '@/lib/utils';
import { cilX } from '@coreui/icons';

export interface ActiveFilter {
    key: string;
    label: string;
    onRemove: () => void;
}

export function ActiveFilterChips({ filters, className }: { filters: readonly ActiveFilter[]; className?: string }) {
    if (filters.length === 0) {
        return null;
    }

    return (
        <ScrollRow role="list" aria-label="Filtros aplicados" className={cn('-my-1 flex gap-2 py-1 lg:hidden', className)}>
            {filters.map((filter) => (
                <span role="listitem" key={filter.key} className="shrink-0">
                    <button
                        type="button"
                        aria-label={`Quitar filtro: ${filter.label}`}
                        onClick={filter.onRemove}
                        className="hit-area bg-primary-soft text-primary-ink hover:border-primary inline-flex h-9 items-center gap-1.5 rounded-full border border-transparent pr-2.5 pl-3.5 text-[13px] font-semibold whitespace-nowrap transition-colors"
                    >
                        {filter.label}
                        <Icon icon={cilX} size={14} className="shrink-0" />
                    </button>
                </span>
            ))}
        </ScrollRow>
    );
}
