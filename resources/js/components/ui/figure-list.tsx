import { cn } from '@/lib/utils';
import { cilChevronRight } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { Icon } from './icon';

export function FigureList({ className, children }: { className?: string; children: ReactNode }) {
    return <div className={cn('figure-list divide-line grid max-lg:divide-y lg:gap-3', className)}>{children}</div>;
}

interface FigureItemProps {
    label: string;
    value: ReactNode;
    href?: string;
}

const ITEM =
    'figure-item flex max-lg:min-h-11 max-lg:items-center max-lg:justify-between max-lg:gap-3 max-lg:py-2.5 lg:bg-surface-2 lg:flex-col lg:gap-0.5 lg:rounded-xl lg:px-3.5 lg:py-3';

export function FigureItem({ label, value, href }: FigureItemProps) {
    const body = (
        <>
            <span className="text-ink-2 lg:text-ink-3 text-sm lg:text-xs">{label}</span>
            <span className="flex items-center gap-1.5">
                <span className="text-base font-bold tabular-nums lg:text-lg lg:font-extrabold">{value}</span>
                {href ? <Icon icon={cilChevronRight} size={14} className="text-ink-3 lg:hidden" /> : null}
            </span>
        </>
    );

    return href ? (
        <Link href={href} className={cn(ITEM, 'lg:hover:bg-base-300')}>
            {body}
        </Link>
    ) : (
        <div className={ITEM}>{body}</div>
    );
}
