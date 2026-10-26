import { Icon } from '@/components/ui/icon';
import { cilChevronLeft, cilChevronRight } from '@coreui/icons';

interface PagerProps {
    page: number;
    hasMore: boolean;
    onPage: (page: number) => void;
}

export function Pager({ page, hasMore, onPage }: PagerProps) {
    if (page === 1 && !hasMore) {
        return null;
    }

    return (
        <nav aria-label="Páginas" className="border-line flex items-center justify-between gap-2 border-t px-5 py-3">
            <span className="text-ink-3 text-[13px]">Página {page}</span>
            <span className="flex gap-2">
                {page > 1 ? (
                    <button type="button" className="btn btn-sm" onClick={() => onPage(page - 1)}>
                        <Icon icon={cilChevronLeft} size={14} />
                        Anterior
                    </button>
                ) : null}
                {hasMore ? (
                    <button type="button" className="btn btn-sm" onClick={() => onPage(page + 1)}>
                        Siguiente
                        <Icon icon={cilChevronRight} size={14} />
                    </button>
                ) : null}
            </span>
        </nav>
    );
}
